using Microsoft.Data.Sqlite;
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Time.Testing;
using MonteFiskal.Extensions.DependencyInjection;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Storage.EntityFrameworkCore;
using MonteFiskal.Storage.FileSystem;
using MonteFiskal.Testing;
using MonteFiskal.Transport;

namespace MonteFiskal.Tests;

public sealed class StorageTests : IDisposable
{
    private readonly SqliteConnection _connection = new("Data Source=:memory:");
    private readonly string _dir = Path.Combine(Path.GetTempPath(), "montefiskal-tests-" + Guid.NewGuid().ToString("N"));

    public StorageTests()
    {
        _connection.Open();
        using var db = Context();
        db.Database.EnsureCreated();
    }

    private MonteFiskalDbContext Context() => new(new DbContextOptionsBuilder<MonteFiskalDbContext>().UseSqlite(_connection).Options);

    public void Dispose()
    {
        _connection.Dispose();
        if (Directory.Exists(_dir))
        {
            Directory.Delete(_dir, true);
        }
    }

    [Fact]
    public async Task Ef_store_allocates_without_gaps_or_duplicates_BR_N5()
    {
        var store = new EfOutboxStore(Context);
        var results = new List<long>();
        for (var i = 0; i < 20; i++)
        {
            var r = await store.IssueAsync("12345678/bb123bb123/cc123cc123/2026", n => new StoredRequest { Body = "<Invoice></Invoice>", Iic = n.ToString("X32", System.Globalization.CultureInfo.InvariantCulture), IssuedAt = DateTimeOffset.UtcNow }, TestContext.Current.CancellationToken);
            results.Add(r.Ordinal!.Value);
        }
        Assert.Equal(Enumerable.Range(1, 20).Select(x => (long)x), results);
        await Assert.ThrowsAsync<InvalidOperationException>(() => store.IssueAsync("12345678/bb123bb123/cc123cc123/2026", _ => throw new InvalidOperationException("build failed"), TestContext.Current.CancellationToken));
        var next = await store.IssueAsync("12345678/bb123bb123/cc123cc123/2026", n => new StoredRequest { Body = "<Invoice></Invoice>", Iic = "F" + n, IssuedAt = DateTimeOffset.UtcNow }, TestContext.Current.CancellationToken);
        Assert.Equal(21, next.Ordinal);
    }

    [Fact]
    public async Task Ef_store_works_with_the_client_end_to_end()
    {
        var clock = new FakeTimeProvider(new DateTimeOffset(2026, 11, 13, 9, 15, 30, TimeSpan.Zero));
        var store = new EfOutboxStore(Context);
        var client = new FiscalClient(ClientTests.Profile, new StaticCertificateProvider(TestCertificateFactory.Create()), new FakeEfiEndpoint(clock), store, null, clock);
        var issued = await client.IssueInvoiceAsync(ClientTests.CardDraft(), TestContext.Current.CancellationToken);
        var sent = await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        Assert.Equal(InvoiceState.Fiscalized, sent.State);
        var back = await store.FindByIicAsync(issued.Iic, TestContext.Current.CancellationToken);
        Assert.Equal(sent.Fic, back!.Result);
        Assert.Empty(await store.PendingAsync(10, TestContext.Current.CancellationToken));
    }

    [Fact]
    public async Task Offline_export_and_import_round_trip_BR_O11()
    {
        var clock = new FakeTimeProvider(new DateTimeOffset(2026, 11, 13, 9, 15, 30, TimeSpan.Zero));
        var store = new InMemoryOutboxStore();
        var fake = new FakeEfiEndpoint(clock) { RequireInitialDeposit = false };
        var archive = new FileArchiveStore(Path.Combine(_dir, "archive"));
        var client = new FiscalClient(ClientTests.Profile, new StaticCertificateProvider(TestCertificateFactory.Create()), fake, store, new MonteFiskalOptions { AutoInitialDeposit = false }, clock, archive: archive);
        var a = await client.IssueInvoiceAsync(ClientTests.CardDraft(), TestContext.Current.CancellationToken);
        var b = await client.IssueInvoiceAsync(ClientTests.CardDraft(3m), TestContext.Current.CancellationToken);
        var pending = await store.PendingAsync(10, TestContext.Current.CancellationToken);
        var zips = OfflineExchange.Export(pending, s => client.SignForExport(s), "cc123cc123", clock.GetUtcNow(), "Europe/Podgorica", _dir);
        Assert.Single(zips);
        Assert.EndsWith("20261113101530_cc123cc123_request.zip", zips[0], StringComparison.Ordinal);
        using (var zip = System.IO.Compression.ZipFile.OpenRead(zips[0]))
        {
            Assert.Equal(2, zip.Entries.Count);
            using var r = new StreamReader(zip.Entries[0].Open());
            var xml = await r.ReadToEndAsync(TestContext.Current.CancellationToken);
            Assert.Contains("SubseqDelivType=\"NOINTERNET\"", xml, StringComparison.Ordinal);
            Assert.True(XmlSignatureVerifier.Verify(xml, "Request").Valid);
        }
        // The service answers each file; simulate the response archive with the fake endpoint.
        var responseZip = Path.Combine(_dir, "20261113120000_cc123cc123_response.zip");
        using (var fs = File.Create(responseZip))
        using (var zip = new System.IO.Compression.ZipArchive(fs, System.IO.Compression.ZipArchiveMode.Create))
        {
            foreach (var s in pending)
            {
                var res = await ((IFiscalTransport)fake).SendAsync(FiscalOperation.RegisterInvoice, SoapEnvelope.Wrap(client.SignForExport(s)), TestContext.Current.CancellationToken);
                var e = zip.CreateEntry(Archive.OfflineNames.ResponseFile(s.IssueDateTime!, "cc123cc123", s.Iic!));
                await using var w = new StreamWriter(e.Open());
                await w.WriteAsync(res.Body);
            }
        }
        foreach (var (iic, xml) in OfflineExchange.ReadResponses(responseZip))
        {
            Assert.True(await client.ApplyImportedResponseAsync(iic, xml, TestContext.Current.CancellationToken));
        }
        Assert.Equal(InvoiceState.Fiscalized, (await store.GetAsync(a.Id, TestContext.Current.CancellationToken))!.State);
        Assert.Equal(InvoiceState.Fiscalized, (await store.GetAsync(b.Id, TestContext.Current.CancellationToken))!.State);
        await client.SendAsync(a.Id, TestContext.Current.CancellationToken);
        Assert.Empty(await archive.LoadAsync(a.Id, TestContext.Current.CancellationToken));
    }

    [Fact]
    public async Task Dependency_injection_builds_a_client_from_configuration()
    {
        var config = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["MonteFiskal:Profile:IssuerTin"] = "12345678",
            ["MonteFiskal:Profile:IssuerName"] = "MonteFiskal Test Only DOO",
            ["MonteFiskal:Profile:IssuerInVat"] = "true",
            ["MonteFiskal:Profile:BusinessUnit"] = "bb123bb123",
            ["MonteFiskal:Profile:Tcr"] = "cc123cc123",
            ["MonteFiskal:Profile:Software"] = "ss123ss123",
            ["MonteFiskal:Profile:Operator"] = "oo123oo123",
            ["MonteFiskal:Options:CashLimit"] = "5000",
            ["MonteFiskal:BackgroundResend"] = "false",
        }).Build();
        var services = new ServiceCollection();
        services.AddLogging();
        var fake = new FakeEfiEndpoint();
        services.AddSingleton<ICertificateProvider>(new StaticCertificateProvider(TestCertificateFactory.Create()));
        services.AddSingleton<IFiscalTransport>(fake);
        services.AddMonteFiskal(config.GetSection("MonteFiskal"));
        await using var sp = services.BuildServiceProvider();
        var client = sp.GetRequiredService<IFiscalClient>();
        var issued = await client.IssueAndSendAsync(ClientTests.CardDraft(), TestContext.Current.CancellationToken);
        Assert.NotNull(issued);
        var ex = await Assert.ThrowsAsync<Validation.FiscalValidationException>(() => client.IssueInvoiceAsync(ClientTests.CardDraft() with
        {
            Payments = [new Payment { Method = PaymentMethod.Banknote }],
            Lines = [new InvoiceLine { Name = "x", Unit = "kom", Quantity = 1, UnitPrice = 6000, VatRate = 21, PriceIncludesVat = true }],
        }, TestContext.Current.CancellationToken));
        Assert.Contains(ex.Result.Errors, f => f.Rule == "BR-A8");
    }
}
