using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Rendering;
using MonteFiskal.Reports;
using MonteFiskal.Security;
using MonteFiskal.Testing;

namespace MonteFiskal.Tests;

public sealed class RenderingAndReportTests
{
    public static TheoryData<string> Qr => VectorTests.Ids("qr");
    public static TheoryData<string> Report => VectorTests.Ids("report");

    [Theory, MemberData(nameof(Qr))]
    public void Qr_vector(string id)
    {
        var v = VectorTests.Run(id);
        var qr = QrCode.Encode(v.Input.GetProperty("text").GetString()!);
        Assert.Equal(v.Expected.GetProperty("version").GetInt32(), qr.Version);
        Assert.Equal(v.Expected.GetProperty("size").GetInt32(), qr.Size);
        Assert.Equal(v.Expected.GetProperty("mask").GetInt32(), qr.Mask);
        var rows = qr.Rows();
        Assert.Equal(v.Expected.GetProperty("modules").EnumerateArray().Select(r => r.GetString()!).ToList(), rows);
        Assert.Equal(v.Expected.GetProperty("modulesSha256").GetString(), Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(string.Join('\n', rows)))));
    }

    [Theory, MemberData(nameof(Report))]
    public void Report_vector(string id)
    {
        var v = VectorTests.Run(id);
        var i = v.Input;
        var invoices = i.GetProperty("invoices").Deserialize<List<ReportInvoice>>(MonteFiskalJson.Options)!;
        var deposits = i.GetProperty("deposits").Deserialize<List<ReportDeposit>>(MonteFiskalJson.Options)!;
        var tcr = i.GetProperty("tcr").GetString()!;
        var bu = i.GetProperty("businessUnit").GetString()!;
        var r = i.GetProperty("report").GetString() == "daily"
            ? InspectorReports.Daily(DateOnly.Parse(i.GetProperty("date").GetString()!, CultureInfo.InvariantCulture), tcr, bu, invoices, deposits)
            : InspectorReports.Periodic(DateOnly.Parse(i.GetProperty("from").GetString()!, CultureInfo.InvariantCulture), DateOnly.Parse(i.GetProperty("to").GetString()!, CultureInfo.InvariantCulture), tcr, bu, invoices, deposits);
        var e = v.Expected;
        static string M(decimal d) => Calculation.Fmt.Money(d);
        Assert.Equal(e.GetProperty("invoiceCount").GetInt32(), r.InvoiceCount);
        Assert.Equal(e.GetProperty("correctiveCount").GetInt32(), r.CorrectiveCount);
        Assert.Equal(e.GetProperty("offlineCount").GetInt32(), r.OfflineCount);
        Assert.Equal(e.GetProperty("totalWithoutVat").GetString(), M(r.TotalWithoutVat));
        Assert.Equal(e.GetProperty("vatAmount").GetString(), M(r.VatAmount));
        Assert.Equal(e.GetProperty("total").GetString(), M(r.Total));
        Assert.Equal(e.GetProperty("byVatRate").EnumerateArray().Select(x => (x.GetProperty("key").GetString(), x.GetProperty("priceBeforeVat").GetString(), x.GetProperty("vatAmount").GetString())).ToList(),
            r.ByVatRate.Select(x => ((string?)x.VatRate, (string?)M(x.PriceBeforeVat), (string?)M(x.VatAmount))).ToList());
        Assert.Equal(e.GetProperty("byPaymentMethod").EnumerateArray().Select(x => (x.GetProperty("method").GetString(), x.GetProperty("amount").GetString())).ToList(),
            r.ByPaymentMethod.Select(x => ((string?)x.Method, (string?)M(x.Amount))).ToList());
        Assert.Equal(e.GetProperty("initialDeposit").GetString(), M(r.InitialDeposit));
        Assert.Equal(e.GetProperty("withdrawals").GetString(), M(r.Withdrawals));
        Assert.Equal(e.GetProperty("expectedCash").GetString(), M(r.ExpectedCash));
        Assert.Equal(e.GetProperty("operators").EnumerateArray().Select(x => x.GetString()!).ToList(), r.Operators);
        Assert.Contains("expectedCash;" + e.GetProperty("expectedCash").GetString(), InspectorReports.ToCsv(r), StringComparison.Ordinal);
    }

    [Fact]
    public void Png_and_svg_are_well_formed_BR_Q8()
    {
        var qr = QrCode.Encode("https://efitest.tax.gov.me/ic/#/verify?iic=0123456789ABCDEF0123456789ABCDEF");
        var png = QrImages.Png(qr, 4);
        Assert.Equal([0x89, 0x50, 0x4E, 0x47], png[..4]);
        var svg = QrImages.Svg(qr, 25);
        Assert.Contains("width=\"25mm\"", svg, StringComparison.Ordinal);
        Assert.Throws<ArgumentOutOfRangeException>(() => QrImages.Svg(qr, 20));
    }

    [Fact]
    public async Task Receipts_render_from_a_stored_invoice()
    {
        var clock = new Microsoft.Extensions.Time.Testing.FakeTimeProvider(new DateTimeOffset(2026, 11, 13, 9, 15, 30, TimeSpan.Zero));
        var store = new InMemoryOutboxStore();
        var client = new FiscalClient(ClientTests.Profile, new StaticCertificateProvider(TestCertificateFactory.Create()), new FakeEfiEndpoint(clock), store, null, clock);
        var issued = await client.IssueInvoiceAsync(ClientTests.CardDraft(), TestContext.Current.CancellationToken);
        var before = Receipt.FromStored((await store.GetAsync(issued.Id, TestContext.Current.CancellationToken))!, testMode: true);
        Assert.Null(before.Fic);
        await client.SendAsync(issued.Id, TestContext.Current.CancellationToken);
        var stored = (await store.GetAsync(issued.Id, TestContext.Current.CancellationToken))!;
        var r = Receipt.FromStored(stored, testMode: true);
        Assert.NotNull(r.Fic);
        var html = HtmlReceipt.Render(r, "me");
        Assert.Contains(r.Iic, html, StringComparison.Ordinal);
        Assert.Contains(r.Fic!, html, StringComparison.Ordinal);
        Assert.Contains("TEST - NIJE FISKALNI", html, StringComparison.Ordinal);
        Assert.Contains("Kod operatera", html, StringComparison.Ordinal);
        var esc = EscPosReceipt.Render(r, 58);
        var text = Encoding.ASCII.GetString(esc);
        Assert.Contains(r.InvoiceNumber, text, StringComparison.Ordinal);
        Assert.Contains("\u001d(k", text, StringComparison.Ordinal);
        var report = InspectorReports.FromStore([stored], "cc123cc123");
        Assert.Single(report.Invoices);
        Assert.True(report.Invoices[0].Fiscalized);
    }
}
