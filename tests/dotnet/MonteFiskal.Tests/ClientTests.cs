using Microsoft.Extensions.Time.Testing;
using System.Text.RegularExpressions;
using MonteFiskal.Archive;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Storage.FileSystem;
using MonteFiskal.Transport;
using MonteFiskal.Testing;
using MonteFiskal.Validation;
using MonteFiskal.Wire;

namespace MonteFiskal.Tests;

public sealed class ClientTests
{
    internal static readonly FiscalProfile Profile = new()
    {
        IssuerTin = "12345678",
        IssuerName = "MonteFiskal Test Only DOO",
        IssuerAddress = "Test Street 1",
        IssuerTown = "Podgorica",
        IssuerInVat = true,
        BusinessUnit = "bb123bb123",
        Tcr = "cc123cc123",
        Software = "ss123ss123",
        Operator = "oo123oo123",
        Maintainer = "mm123mm123",
    };

    internal static InvoiceDraft CardDraft(decimal price = 12.10m) => new()
    {
        Lines = [new InvoiceLine { Name = "Test item", Unit = "kom", Quantity = 1, UnitPrice = price, VatRate = 21, PriceIncludesVat = true }],
        Payments = [new Payment { Method = PaymentMethod.Card }],
    };

    private static (FiscalClient Client, FakeEfiEndpoint Fake, InMemoryOutboxStore Store, FakeTimeProvider Clock) Create(MonteFiskalOptions? options = null, IArchiveStore? archive = null)
    {
        var clock = new FakeTimeProvider(new DateTimeOffset(2026, 11, 13, 9, 15, 30, TimeSpan.Zero));
        var fake = new FakeEfiEndpoint(clock);
        var store = new InMemoryOutboxStore();
        var client = new FiscalClient(Profile, new StaticCertificateProvider(TestCertificateFactory.Create()), fake, store, options, clock, archive: archive);
        return (client, fake, store, clock);
    }

    [Fact]
    public async Task Issue_and_send_registers_initial_deposit_first_BR_D5()
    {
        var (client, fake, _, _) = Create();
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        Assert.Equal("bb123bb123/1/2026/cc123cc123", issued.Number);
        Assert.Equal(TypeOfInvoice.Cash, issued.TypeOfInv);
        Assert.Matches("^[0-9A-F]{32}$", issued.Iic);
        Assert.StartsWith("https://efitest.tax.gov.me/ic/#/verify?iic=" + issued.Iic, issued.VerificationUrl, StringComparison.Ordinal);
        var sent = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, sent.State);
        Assert.NotNull(sent.Fic);
        Assert.Equal(["RegisterCashDepositRequest", "RegisterInvoiceRequest"], fake.Exchanges.Select(x => x.RequestElement));
        Assert.Contains("Operation=\"INITIAL\"", fake.Exchanges[0].Body, StringComparison.Ordinal);
        Assert.Contains("CashAmt=\"0.00\"", fake.Exchanges[0].Body, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Without_initial_deposit_cash_invoice_is_refused_by_the_service_code_58()
    {
        var (client, fake, _, _) = Create(new MonteFiskalOptions { AutoInitialDeposit = false });
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        var sent = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Rejected, sent.State);
        Assert.Equal(58, sent.Error!.Code);
        Assert.Single(fake.Exchanges);
    }

    [Fact]
    public async Task Request_signature_verifies_with_the_platform_implementation_BR_S1_to_BR_S4()
    {
        var (client, fake, store, _) = Create();
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        var stored = await store.GetAsync(issued.Id, TestContext.Current.CancellationToken);
        var check = XmlSignatureVerifier.Verify(stored!.LastSignedRequest!, "Request");
        Assert.True(check.Valid, check.Problem);
        Assert.Empty(SchemaSet.Validate(stored.LastSignedRequest!));
        Assert.All(fake.Exchanges, x => Assert.Equal(200, x.StatusCode));
    }

    [Fact]
    public async Task Response_signature_is_verified_BR_S6()
    {
        var (client, fake, store, _) = Create(new MonteFiskalOptions { ResponseSignature = ResponseSignaturePolicy.Require });
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        var sent = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, sent.State);
        var parsed = ResponseParser.Parse(200, (await store.GetAsync(issued.Id, TestContext.Current.CancellationToken))!.LastResponse);
        Assert.True(parsed.Signature!.Valid, parsed.Signature.Problem);
        Assert.Equal(fake.ServiceCertificate.Thumbprint, parsed.Signature.Certificate!.Thumbprint);
    }

    [Fact]
    public async Task Tampered_response_is_malformed_when_signature_is_required()
    {
        var (client, fake, _, _) = Create(new MonteFiskalOptions { ResponseSignature = ResponseSignaturePolicy.Require, AutoInitialDeposit = false });
        fake.RequireInitialDeposit = false;
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        fake.SignResponses = true;
        var good = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, good.State);
        var other = await client.IssueInvoiceAsync(CardDraft(5m), TestContext.Current.CancellationToken);
        fake.SignResponses = false;
        var unsigned = await client.SendAsync(other.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Pending, unsigned.State);
    }

    [Fact]
    public async Task Resend_keeps_body_and_IKOF_and_sets_SubseqDelivType_BR_O7()
    {
        var (client, fake, store, clock) = Create();
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        fake.FailNextWithHttp(503).FailNextWithHttp(503);
        var first = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Pending, first.State);
        clock.Advance(TimeSpan.FromMinutes(10));
        var summary = await client.ResendPendingAsync(new ResendOptions(), TestContext.Current.CancellationToken);
        Assert.True(summary.Fiscalized >= 1);
        var stored = await store.GetAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, stored!.State);
        Assert.Contains("SubseqDelivType=\"NOINTERNET\"", stored.LastSignedRequest, StringComparison.Ordinal);
        Assert.Contains(stored.Body, stored.LastSignedRequest, StringComparison.Ordinal);
        Assert.Contains("IIC=\"" + issued.Iic + "\"", stored.LastSignedRequest, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Server_fault_is_retryable_and_becomes_pending()
    {
        var (client, fake, _, _) = Create(new MonteFiskalOptions { AutoInitialDeposit = false });
        fake.RequireInitialDeposit = false;
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        fake.FailNextWith(999, "Internal error.", server: true);
        var r = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Pending, r.State);
        Assert.True(r.Error!.Retryable);
    }

    [Fact]
    public async Task Overdue_after_48_hours_BR_O12()
    {
        var (client, fake, _, clock) = Create(new MonteFiskalOptions { AutoInitialDeposit = false });
        fake.RequireInitialDeposit = false;
        var issued = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        clock.Advance(TimeSpan.FromHours(49));
        fake.FailNextWithHttp(502);
        var r = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Overdue, r.State);
    }

    [Fact]
    public async Task Validation_error_consumes_no_ordinal_BR_N5()
    {
        var (client, _, _, _) = Create();
        var bad = CardDraft() with { ReverseCharge = true };
        var ex = await Assert.ThrowsAsync<FiscalValidationException>(() => client.IssueInvoiceAsync(bad, TestContext.Current.CancellationToken));
        Assert.Contains(ex.Result.Errors, f => f.Rule == "BR-K11");
        var ok = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        Assert.Equal(1, ok.Ordinal);
        var next = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        Assert.Equal(2, next.Ordinal);
    }

    [Fact]
    public async Task Schema_error_consumes_no_ordinal_BR_O10()
    {
        var (client, _, _, _) = Create();
        var bad = CardDraft() with { Lines = [new InvoiceLine { Name = new string('x', 51), Unit = "kom", Quantity = 1, UnitPrice = 1, VatRate = 21 }] };
        var ex = await Assert.ThrowsAsync<FiscalValidationException>(() => client.IssueInvoiceAsync(bad, TestContext.Current.CancellationToken));
        Assert.Contains(ex.Result.Errors, f => f.Rule == "SCHEMA");
        Assert.Equal(1, (await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken)).Ordinal);
    }

    [Fact]
    public async Task Corrective_waits_for_original_BR_C3_and_reverses_quantities_BR_C2()
    {
        var (client, fake, store, _) = Create();
        var original = await client.IssueInvoiceAsync(CardDraft(), TestContext.Current.CancellationToken);
        var corrective = await client.IssueCorrectiveAsync(new CorrectionRequest(original.Id), TestContext.Current.CancellationToken);
        Assert.Equal("-12.10", corrective.Totals.TotPrice);
        var early = await client.SendAsync(corrective.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Issued, early.State);
        Assert.Contains("BR-C3", early.Problem, StringComparison.Ordinal);
        await client.SendAsync(original.Id, TestContext.Current.CancellationToken);
        var late = await client.SendAsync(corrective.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, late.State);
        var body = (await store.GetAsync(corrective.Id, TestContext.Current.CancellationToken))!.Body;
        Assert.Contains("<CorrectiveInv IICRef=\"" + original.Iic + "\"", body, StringComparison.Ordinal);
        Assert.Contains("Q=\"-1\"", body, StringComparison.Ordinal);
        Assert.Contains("UPA=\"12.1000\"", body, StringComparison.Ordinal);
        Assert.Equal(3, fake.Exchanges.Count);
    }

    [Fact]
    public async Task Tcr_registration_returns_the_same_code_for_the_same_internal_id_BR_R3()
    {
        var (client, _, _, _) = Create();
        var reg = new TcrRegistration { IssuerTin = "12345678", BusinessUnit = "bb123bb123", InternalId = "1", Software = "ss123ss123", Maintainer = "mm123mm123", Type = TcrType.Regular };
        var a = await client.RegisterTcrAsync(reg, TestContext.Current.CancellationToken);
        var b = await client.RegisterTcrAsync(reg, TestContext.Current.CancellationToken);
        Assert.True(a.Success);
        Assert.Equal(a.TcrCode, b.TcrCode);
        Assert.Matches("^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$", a.TcrCode!);
    }

    [Fact]
    public async Task Withdraw_zero_is_refused_locally_BR_D2()
    {
        var (client, _, _, _) = Create();
        await Assert.ThrowsAsync<FiscalValidationException>(() => client.RegisterCashDepositAsync(new CashDeposit { IssuerTin = "12345678", Tcr = "cc123cc123", Operation = CashDepositOperation.Withdraw, Amount = 0 }, TestContext.Current.CancellationToken));
    }

    [Fact]
    public void Recompute_ikof_is_deterministic_BR_I6()
    {
        var (client, _, _, _) = Create();
        var p = new IkofParameters("12345678", "2026-11-13T10:15:30+01:00", "1", "bb123bb123", "cc123cc123", "ss123ss123", "12.10");
        Assert.Equal(client.RecomputeIkof(p), client.RecomputeIkof(p));
    }

    [Fact]
    public async Task Transliteration_is_optional_Q07()
    {
        var (client, _, store, _) = Create(new MonteFiskalOptions { Text = TextPolicy.Transliterate });
        var d = CardDraft() with { Lines = [new InvoiceLine { Name = "Čokolada đak", Unit = "kom", Quantity = 1, UnitPrice = 2, VatRate = 21, PriceIncludesVat = true }] };
        var issued = await client.IssueInvoiceAsync(d, TestContext.Current.CancellationToken);
        Assert.Empty(issued.Warnings);
        Assert.Contains("N=\"Cokolada djak\"", (await store.GetAsync(issued.Id, TestContext.Current.CancellationToken))!.Body, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Duplicate_initial_counts_as_success_Q17()
    {
        var (client, fake, _, _) = Create();
        fake.FailNextWith(56, "INITIAL already registered for the current day.");
        var r = await client.RegisterCashDepositAsync(new CashDeposit { IssuerTin = "12345678", Tcr = "cc123cc123", Operation = CashDepositOperation.Initial, Amount = 0 }, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, r.State);
    }

    [Fact]
    public void Certificate_checks_find_the_tin_and_key_size()
    {
        using var cert = TestCertificateFactory.Create("12345678");
        var (errors, warnings) = CertificateChecks.Inspect(cert, "12345678", DateTimeOffset.UtcNow);
        Assert.Empty(errors);
        Assert.Empty(warnings);
        var other = CertificateChecks.Inspect(cert, "87654321", DateTimeOffset.UtcNow);
        Assert.Single(other.Warnings);
        Assert.NotEmpty(CertificateChecks.Inspect(cert, "12345678", DateTimeOffset.UtcNow.AddYears(2)).Errors);
    }

    private static readonly DateTimeOffset Day = new(2026, 11, 13, 0, 0, 0, TimeSpan.Zero);

    private static string TempDir() => Directory.CreateTempSubdirectory("montefiskal-").FullName;

    [Fact]
    public async Task Pair_export_takes_the_attempt_that_received_the_JIKR_BR_X7()
    {
        var ct = TestContext.Current.CancellationToken;
        var root = TempDir();
        var archive = new FileArchiveStore(Path.Combine(root, "archive"));
        var (client, fake, store, clock) = Create(archive: archive);
        var issued = await client.IssueInvoiceAsync(CardDraft(), ct);
        fake.FailNextWithHttp(503).FailNextWithHttp(503);
        Assert.Equal(InvoiceState.Pending, (await client.SendAsync(issued.Id, ct)).State);
        clock.Advance(TimeSpan.FromMinutes(10));
        await client.ResendPendingAsync(new ResendOptions(), ct);
        var stored = await store.GetAsync(issued.Id, ct);
        Assert.Equal(InvoiceState.Fiscalized, stored!.State);

        var result = await InvoicePairs.ExportAsync(store, archive, Day, Day.AddDays(1), Path.Combine(root, "out"), ct);
        Assert.Equal(1, result.Pairs);
        Assert.Empty(result.Skipped);
        Assert.EndsWith("_cc123cc123_" + issued.Iic + "_request.xml", result.Written[0], StringComparison.Ordinal);
        Assert.EndsWith("_cc123cc123_" + issued.Iic + "_response.xml", result.Written[1], StringComparison.Ordinal);
        var request = await File.ReadAllTextAsync(result.Written[0], ct);
        var response = await File.ReadAllTextAsync(result.Written[1], ct);
        Assert.Contains("SubseqDelivType=\"NOINTERNET\"", request, StringComparison.Ordinal);
        var uuid = Regex.Match(request, "<Header\\b[^>]*\\bUUID=\"([^\"]+)\"").Groups[1].Value;
        Assert.Equal(Guid.Parse(uuid), ResponseParser.Parse(200, response).RequestUuid);
        Assert.Equal(stored.LastSignedRequest, request);
        Assert.Equal(stored.LastResponse, response);
        Assert.True(XmlSignatureVerifier.Verify(request, "Request").Valid);
    }

    [Fact]
    public async Task Offline_export_and_import_are_archived_and_pair_BR_X2_BR_X7()
    {
        var ct = TestContext.Current.CancellationToken;
        var root = TempDir();
        var archive = new FileArchiveStore(Path.Combine(root, "archive"));
        var (client, fake, store, _) = Create(new MonteFiskalOptions { AutoInitialDeposit = false }, archive);
        fake.RequireInitialDeposit = false;
        var issued = await client.IssueInvoiceAsync(CardDraft(), ct);
        var stored = await store.GetAsync(issued.Id, ct);
        var exported = await client.SignForExportAsync(stored!, cancellationToken: ct);
        var kept = await archive.LoadAsync(issued.Id, ct);
        Assert.Equal([("request", exported)], kept);

        // The Tax Administration answers the exported request; the answer comes back in a _response.zip.
        var answer = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" + (await ((IFiscalTransport)fake).SendAsync(FiscalOperation.RegisterInvoice, SoapEnvelope.Wrap(exported), ct)).Body;
        Assert.True(await client.ApplyImportedResponseAsync(issued.Iic, answer, ct));
        Assert.Equal(answer, (await archive.LoadAsync(issued.Id, ct))[1].Content);

        var result = await InvoicePairs.ExportAsync(store, archive, Day, Day.AddDays(1), Path.Combine(root, "out"), ct);
        Assert.Equal(1, result.Pairs);
        Assert.Equal(exported, await File.ReadAllTextAsync(result.Written[0], ct));
        Assert.Equal(answer, await File.ReadAllTextAsync(result.Written[1], ct));
    }

    [Fact]
    public async Task Pair_export_skips_invoices_without_JIKR_BR_X7()
    {
        var ct = TestContext.Current.CancellationToken;
        var root = TempDir();
        var archive = new FileArchiveStore(Path.Combine(root, "archive"));
        var (client, fake, store, _) = Create(archive: archive);
        var issued = await client.IssueInvoiceAsync(CardDraft(), ct);
        fake.FailNextWithHttp(503);
        await client.SendAsync(issued.Id, ct);
        var result = await InvoicePairs.ExportAsync(store, archive, Day, Day.AddDays(1), Path.Combine(root, "out"), ct);
        Assert.Empty(result.Written);
        Assert.Equal([new PairSkip(issued.Id, issued.Iic, InvoicePairs.NotFiscalized)], result.Skipped);
    }
}
