using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Transport;

namespace MonteFiskal.IntegrationTests;

/// <summary>
/// Opt-in scenarios against https://efitest.tax.gov.me with the user's own test certificate and codes.
/// Every test is skipped unless MONTEFISKAL_IT_ENABLED=1 and all MONTEFISKAL_IT_* variables are set.
/// The production host is refused. Results feed the open questions listed in docs/STATUS.md.
/// </summary>
public sealed class EfitestScenarios
{
    private static string? E(string n) => Environment.GetEnvironmentVariable(n);

    private static readonly string[] Required =
    [
        "MONTEFISKAL_IT_CERT_PATH", "MONTEFISKAL_IT_CERT_PASSWORD", "MONTEFISKAL_IT_TIN", "MONTEFISKAL_IT_NAME", "MONTEFISKAL_IT_IN_VAT",
        "MONTEFISKAL_IT_BU", "MONTEFISKAL_IT_TCR", "MONTEFISKAL_IT_SOFTWARE", "MONTEFISKAL_IT_OPERATOR",
    ];

    private static (FiscalClient Client, InMemoryOutboxStore Store, FiscalProfile Profile) Create(MonteFiskalOptions? options = null)
    {
        Assert.SkipUnless(E("MONTEFISKAL_IT_ENABLED") == "1" && Required.All(n => !string.IsNullOrEmpty(E(n))),
            "efitest scenarios are opt-in: set MONTEFISKAL_IT_ENABLED=1 and the MONTEFISKAL_IT_* variables.");
        var endpoint = new Uri(E("MONTEFISKAL_IT_ENDPOINT") ?? FiscalEndpoints.Test.ToString());
        Assert.False(endpoint.Host.Equals(FiscalEndpoints.Production.Host, StringComparison.OrdinalIgnoreCase), "Integration tests never call the production service.");
        var profile = new FiscalProfile
        {
            Environment = FiscalEnvironment.Test,
            IssuerTin = E("MONTEFISKAL_IT_TIN")!,
            IssuerName = E("MONTEFISKAL_IT_NAME")!,
            IssuerInVat = bool.Parse(E("MONTEFISKAL_IT_IN_VAT")!),
            BusinessUnit = E("MONTEFISKAL_IT_BU")!,
            Tcr = E("MONTEFISKAL_IT_TCR")!,
            Software = E("MONTEFISKAL_IT_SOFTWARE")!,
            Operator = E("MONTEFISKAL_IT_OPERATOR")!,
            Maintainer = E("MONTEFISKAL_IT_MAINTAINER"),
        };
        options ??= new MonteFiskalOptions();
        var store = new InMemoryOutboxStore();
        var client = new FiscalClient(profile, Pkcs12CertificateProvider.FromEnvironment(E("MONTEFISKAL_IT_CERT_PATH")!, "MONTEFISKAL_IT_CERT_PASSWORD"),
            new HttpFiscalTransport(new HttpClient(), endpoint, options), store, options);
        return (client, store, profile);
    }

    private static InvoiceDraft Draft(FiscalProfile p, PaymentMethod method = PaymentMethod.Card, string name = "MonteFiskal test item") => new()
    {
        Lines = [new InvoiceLine { Name = name, Unit = "kom", Quantity = 1, UnitPrice = 1.21m, VatRate = p.IssuerInVat ? 21 : null, PriceIncludesVat = true }],
        Payments = [new Payment { Method = method }],
    };

    private static void Report(string scenario, object value) => TestContext.Current.SendDiagnosticMessage($"[{scenario}] {value}");

    [Fact] // Scenario 1, BR-D5: INITIAL 0.00, then a cash invoice paid by card.
    public async Task S01_initial_deposit_and_card_invoice()
    {
        var (client, _, p) = Create();
        var d = await client.RegisterCashDepositAsync(new CashDeposit { IssuerTin = p.IssuerTin, Tcr = p.Tcr, Operation = CashDepositOperation.Initial, Amount = 0 }, TestContext.Current.CancellationToken);
        Report("S01 deposit", $"{d.State} {d.Fcdc} {d.Error}");
        var r = await client.IssueAndSendAsync(Draft(p), TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        Report("S01 invoice", $"{r.Invoice.Number} IKOF {r.Invoice.Iic} JIKR {r.Fic} {r.Invoice.VerificationUrl}");
    }

    [Fact] // Scenario 2, BR-K3: non-cash invoice paid by bank transfer.
    public async Task S02_noncash_invoice()
    {
        var (client, _, p) = Create();
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        Report("S02", r.Fic);
    }

    [Fact] // Scenario 3, BR-C2/BR-C3: full corrective invoice of a registered invoice.
    public async Task S03_corrective_invoice()
    {
        var (client, _, p) = Create();
        var original = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Assert.NotNull(original);
        var corr = await client.IssueCorrectiveAsync(new CorrectionRequest(original.Invoice.Id), TestContext.Current.CancellationToken);
        var sent = await client.SendAsync(corr.Id, TestContext.Current.CancellationToken);
        Report("S03", $"{sent.State} {sent.Fic} {sent.Error}");
        Assert.Equal(InvoiceState.Fiscalized, sent.State);
    }

    [Fact] // Scenario 6, Q-14: gross lines whose net parts round apart.
    public async Task S06_rounding_of_gross_lines()
    {
        var (client, _, p) = Create();
        var d = Draft(p, PaymentMethod.Account) with
        {
            Lines =
            [
                new InvoiceLine { Name = "Item 1", Unit = "kom", Quantity = 1, UnitPrice = 9.99m, VatRate = p.IssuerInVat ? 21 : null, PriceIncludesVat = true },
                new InvoiceLine { Name = "Item 2", Unit = "kom", Quantity = 3, UnitPrice = 0.99m, VatRate = p.IssuerInVat ? 21 : null, PriceIncludesVat = true },
                new InvoiceLine { Name = "Item 3", Unit = "kom", Quantity = 1, UnitPrice = 4.49m, VatRate = p.IssuerInVat ? 7 : null, PriceIncludesVat = true },
            ],
        };
        var r = await client.IssueAndSendAsync(d, TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        Report("S06", $"{r.Fic}: check the deferred checks on the portal the next day");
    }

    [Fact] // Scenario 7, Q-07: non-ASCII text sent unchanged.
    public async Task S07_non_ascii_text()
    {
        var (client, _, p) = Create();
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account, "Čokolada šećer žđ"), TestContext.Current.CancellationToken);
        Report("S07", r is null ? "not accepted" : "accepted " + r.Fic);
    }

    [Fact] // Scenario 10, Q-09: the same IKOF sent twice.
    public async Task S10_same_iic_sent_twice()
    {
        var (client, store, p) = Create();
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        var stored = await store.GetAsync(r.Invoice.Id, TestContext.Current.CancellationToken);
        await store.UpdateAsync(stored! with { State = InvoiceState.Pending }, TestContext.Current.CancellationToken);
        var again = await client.SendAsync(r.Invoice.Id, TestContext.Current.CancellationToken);
        Report("S10", $"first {r.Fic}, second {again.State} {again.Fic} {again.Error}");
    }

    [Fact] // Scenario 11, Q-12: response signature and its certificate.
    public async Task S11_response_signature()
    {
        var (client, store, p) = Create();
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        var raw = (await store.GetAsync(r.Invoice.Id, TestContext.Current.CancellationToken))!.LastResponse!;
        var check = XmlSignatureVerifier.Verify(raw, "Response");
        Report("S11", $"present {check.Present} valid {check.Valid} subject {check.Certificate?.Subject} issuer {check.Certificate?.Issuer}");
    }

    [Fact] // Scenario 12, Q-23: IssueDateTime in UTC with Z.
    public async Task S12_utc_date_time()
    {
        var (client, _, p) = Create(new MonteFiskalOptions { DateTimeFormat = DateTimeFormat.Utc });
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Report("S12", r is null ? "not accepted" : r.Invoice.IssueDateTime + " " + r.Fic);
    }

    [Fact] // Scenario 13, Q-22: without SOAPAction header.
    public async Task S13_without_soap_action()
    {
        var (client, _, p) = Create(new MonteFiskalOptions { SendSoapAction = false });
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Report("S13", r is null ? "not accepted" : "accepted " + r.Fic);
    }

    [Fact] // Scenario 14, Q-06: both URL forms of one registered invoice, to open by hand.
    public async Task S14_verification_url_forms()
    {
        var (client, _, p) = Create();
        var r = await client.IssueAndSendAsync(Draft(p, PaymentMethod.Account), TestContext.Current.CancellationToken);
        Assert.NotNull(r);
        var parameters = new IkofParameters(p.IssuerTin, r.Invoice.IssueDateTime, r.Invoice.Ordinal.ToString(System.Globalization.CultureInfo.InvariantCulture), p.BusinessUnit, p.Tcr, p.Software, r.Invoice.Totals.TotPrice);
        Report("S14 percent", Verification.VerificationUrl.Build(Verification.VerificationUrl.TestBase, r.Invoice.Iic, parameters, VerificationUrlEncoding.Percent));
        Report("S14 none", Verification.VerificationUrl.Build(Verification.VerificationUrl.TestBase, r.Invoice.Iic, parameters, VerificationUrlEncoding.None));
    }

    [Fact] // Scenario 15, BR-D2: WITHDRAW of a positive amount.
    public async Task S15_withdraw()
    {
        var (client, _, p) = Create();
        var d = await client.RegisterCashDepositAsync(new CashDeposit { IssuerTin = p.IssuerTin, Tcr = p.Tcr, Operation = CashDepositOperation.Withdraw, Amount = 1m }, TestContext.Current.CancellationToken);
        Report("S15", $"{d.State} {d.Fcdc} {d.Error}");
    }
}
