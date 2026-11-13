using System.Globalization;
using System.Text;
using System.Xml;
using MonteFiskal.Calculation;
using MonteFiskal.Model;
using MonteFiskal.Outbox;
using MonteFiskal.Transport;

namespace MonteFiskal.Reports;

public sealed record ReportVat(string VatRate, decimal PriceBeforeVat, decimal VatAmount);

public sealed record ReportPayment(string Method, decimal Amount);

public sealed record ReportItem(string Name, string Unit, decimal Quantity, decimal PriceAfterVat, string? VatRate);

/// <summary>One invoice as the reports see it.</summary>
public sealed record ReportInvoice
{
    public required string Number { get; init; }
    public string Kind { get; init; } = "INVOICE";
    public string TypeOfInv { get; init; } = "CASH";
    public required string IssueDateTime { get; init; }
    public string Operator { get; init; } = "";
    public bool Fiscalized { get; init; }
    public decimal TotalWithoutVat { get; init; }
    public decimal VatAmount { get; init; }
    public decimal Total { get; init; }
    public IReadOnlyList<ReportVat> SameTaxes { get; init; } = [];
    public IReadOnlyList<ReportPayment> Payments { get; init; } = [];
    public IReadOnlyList<ReportItem> Items { get; init; } = [];
    public string? Iic { get; init; }
    public string? Fic { get; init; }

    /// <summary>Reads a stored invoice request.</summary>
    public static ReportInvoice FromStored(StoredRequest s)
    {
        ArgumentNullException.ThrowIfNull(s);
        var doc = new XmlDocument { XmlResolver = null };
        doc.LoadXml(s.Body);
        var inv = doc.DocumentElement!;
        IEnumerable<XmlElement> Kids(string parent, string child) =>
            inv.ChildNodes.OfType<XmlElement>().Where(e => e.LocalName == parent).SelectMany(e => e.ChildNodes.OfType<XmlElement>()).Where(e => e.LocalName == child);
        static decimal D(string v) => string.IsNullOrEmpty(v) ? 0m : decimal.Parse(v, CultureInfo.InvariantCulture);
        return new ReportInvoice
        {
            Number = inv.GetAttribute("InvNum"),
            Kind = inv.GetAttribute("InvType"),
            TypeOfInv = inv.GetAttribute("TypeOfInv"),
            IssueDateTime = inv.GetAttribute("IssueDateTime"),
            Operator = inv.GetAttribute("OperatorCode"),
            Fiscalized = s.State == InvoiceState.Fiscalized,
            TotalWithoutVat = D(inv.GetAttribute("TotPriceWoVAT")),
            VatAmount = D(inv.GetAttribute("TotVATAmt")),
            Total = D(inv.GetAttribute("TotPrice")),
            SameTaxes = Kids("SameTaxes", "SameTax").Select(g => new ReportVat(g.HasAttribute("VATRate") ? g.GetAttribute("VATRate") : g.GetAttribute("ExemptFromVAT"), D(g.GetAttribute("PriceBefVAT")), D(g.GetAttribute("VATAmt")))).ToList(),
            Payments = Kids("PayMethods", "PayMethod").Select(p => new ReportPayment(p.GetAttribute("Type"), D(p.GetAttribute("Amt")))).ToList(),
            Items = Kids("Items", "I").Select(i => new ReportItem(i.GetAttribute("N"), i.GetAttribute("U"), D(i.GetAttribute("Q")), D(i.GetAttribute("PA")), i.HasAttribute("VR") ? i.GetAttribute("VR") : null)).ToList(),
            Iic = inv.GetAttribute("IIC"),
            Fic = s.Result,
        };
    }
}

/// <summary>A cash deposit as the reports see it.</summary>
public sealed record ReportDeposit(string Operation, decimal Amount, string ChangeDateTime);

/// <summary>Aggregates of a turnover report (BR-X3 items 1 to 3).</summary>
public sealed record TurnoverReport(
    string Title,
    string Tcr,
    string BusinessUnit,
    DateOnly From,
    DateOnly To,
    int InvoiceCount,
    int CorrectiveCount,
    int OfflineCount,
    decimal TotalWithoutVat,
    decimal VatAmount,
    decimal Total,
    IReadOnlyList<ReportVat> ByVatRate,
    IReadOnlyList<ReportPayment> ByPaymentMethod,
    decimal InitialDeposit,
    decimal Withdrawals,
    decimal ExpectedCash,
    IReadOnlyList<string> Operators);

/// <summary>The inspector reports (BR-X3) and their CSV export.</summary>
public static class InspectorReports
{
    /// <summary>(2) Daily fiscal report for one TCR, 00:00 to 23:59:59 local time.</summary>
    public static TurnoverReport Daily(DateOnly date, string tcr, string businessUnit, IEnumerable<ReportInvoice> invoices, IEnumerable<ReportDeposit> deposits) =>
        Turnover("Daily fiscal report", date, date, tcr, businessUnit, invoices, deposits);

    /// <summary>(3) Periodic fiscal turnover report.</summary>
    public static TurnoverReport Periodic(DateOnly from, DateOnly to, string tcr, string businessUnit, IEnumerable<ReportInvoice> invoices, IEnumerable<ReportDeposit> deposits) =>
        Turnover("Periodic turnover report", from, to, tcr, businessUnit, invoices, deposits);

    /// <summary>(1) Fiscal state cut: current turnover of the day up to now.</summary>
    public static TurnoverReport StateCut(DateTimeOffset nowLocal, string tcr, string businessUnit, IEnumerable<ReportInvoice> invoices, IEnumerable<ReportDeposit> deposits)
    {
        var day = DateOnly.FromDateTime(nowLocal.DateTime);
        return Turnover("Fiscal state cut", day, day, tcr, businessUnit, invoices.Where(i => Parse(i.IssueDateTime) <= nowLocal), deposits.Where(d => Parse(d.ChangeDateTime) <= nowLocal));
    }

    /// <summary>(4) Analytic journal with items, (5) offline journal, (6) corrective journal, each with daily subtotals.</summary>
    public static IReadOnlyList<(DateOnly Day, IReadOnlyList<ReportInvoice> Invoices, decimal Subtotal)> Journal(DateOnly from, DateOnly to, IEnumerable<ReportInvoice> invoices, JournalKind kind)
    {
        var selected = invoices.Where(i => InRange(i.IssueDateTime, from, to)).Where(i => kind switch
        {
            JournalKind.Offline => !i.Fiscalized,
            JournalKind.Corrective => i.Kind == "CORRECTIVE",
            _ => true,
        });
        return selected.GroupBy(i => LocalDay(i.IssueDateTime)).OrderBy(g => g.Key)
            .Select(g => (g.Key, (IReadOnlyList<ReportInvoice>)g.OrderBy(i => i.IssueDateTime, StringComparer.Ordinal).ToList(), g.Sum(i => i.Total))).ToList();
    }

    private static TurnoverReport Turnover(string title, DateOnly from, DateOnly to, string tcr, string bu, IEnumerable<ReportInvoice> invoices, IEnumerable<ReportDeposit> deposits)
    {
        var inv = invoices.Where(i => InRange(i.IssueDateTime, from, to)).ToList();
        var dep = deposits.Where(d => InRange(d.ChangeDateTime, from, to)).ToList();
        var byRate = inv.SelectMany(i => i.SameTaxes).GroupBy(g => g.VatRate)
            .OrderBy(g => decimal.TryParse(g.Key, NumberStyles.Number, CultureInfo.InvariantCulture, out var d) ? d : decimal.MaxValue).ThenBy(g => g.Key, StringComparer.Ordinal)
            .Select(g => new ReportVat(g.Key, g.Sum(x => x.PriceBeforeVat), g.Sum(x => x.VatAmount))).ToList();
        var byMethod = inv.SelectMany(i => i.Payments).GroupBy(p => p.Method).OrderBy(g => g.Key, StringComparer.Ordinal)
            .Select(g => new ReportPayment(g.Key, g.Sum(x => x.Amount))).ToList();
        var initial = dep.Where(d => d.Operation == "INITIAL").OrderBy(d => d.ChangeDateTime, StringComparer.Ordinal).Select(d => d.Amount).LastOrDefault();
        var withdrawals = dep.Where(d => d.Operation == "WITHDRAW").Sum(d => d.Amount);
        var banknotes = inv.SelectMany(i => i.Payments).Where(p => p.Method == "BANKNOTE").Sum(p => p.Amount);
        return new TurnoverReport(title, tcr, bu, from, to, inv.Count, inv.Count(i => i.Kind == "CORRECTIVE"), inv.Count(i => !i.Fiscalized),
            inv.Sum(i => i.TotalWithoutVat), inv.Sum(i => i.VatAmount), inv.Sum(i => i.Total), byRate, byMethod,
            // BR-D3: expected cash = initial + BANKNOTE sales − withdrawals; card payments do not change it.
            initial, withdrawals, initial + banknotes - withdrawals,
            inv.Select(i => i.Operator).Where(o => o.Length > 0).Distinct().Order(StringComparer.Ordinal).ToList());
    }

    /// <summary>CSV (semicolon separated, UTF-8) of a turnover report.</summary>
    public static string ToCsv(TurnoverReport r)
    {
        ArgumentNullException.ThrowIfNull(r);
        var sb = new StringBuilder();
        void L(params string[] v) => sb.AppendLine(string.Join(';', v.Select(Csv)));
        L("report", r.Title);
        L("tcr", r.Tcr);
        L("businessUnit", r.BusinessUnit);
        L("from", r.From.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture));
        L("to", r.To.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture));
        L("operators", string.Join(' ', r.Operators));
        L("invoiceCount", r.InvoiceCount.ToString(CultureInfo.InvariantCulture));
        L("correctiveCount", r.CorrectiveCount.ToString(CultureInfo.InvariantCulture));
        L("offlineCount", r.OfflineCount.ToString(CultureInfo.InvariantCulture));
        L("totalWithoutVat", Fmt.Money(r.TotalWithoutVat));
        L("vatAmount", Fmt.Money(r.VatAmount));
        L("total", Fmt.Money(r.Total));
        foreach (var v in r.ByVatRate)
        {
            L("vat", v.VatRate, Fmt.Money(v.PriceBeforeVat), Fmt.Money(v.VatAmount));
        }
        foreach (var p in r.ByPaymentMethod)
        {
            L("payment", p.Method, Fmt.Money(p.Amount));
        }
        L("initialDeposit", Fmt.Money(r.InitialDeposit));
        L("withdrawals", Fmt.Money(r.Withdrawals));
        L("expectedCash", Fmt.Money(r.ExpectedCash));
        return sb.ToString();
    }

    /// <summary>Reads stored requests of a TCR into report records.</summary>
    public static (IReadOnlyList<ReportInvoice> Invoices, IReadOnlyList<ReportDeposit> Deposits) FromStore(IEnumerable<StoredRequest> stored, string tcr)
    {
        var list = stored.Where(s => s.Tcr == tcr).ToList();
        var invoices = list.Where(s => s.Operation == FiscalOperation.RegisterInvoice).Select(ReportInvoice.FromStored).ToList();
        var deposits = list.Where(s => s.Operation == FiscalOperation.RegisterCashDeposit && s.State == InvoiceState.Fiscalized).Select(s =>
        {
            var doc = new XmlDocument { XmlResolver = null };
            doc.LoadXml(s.Body);
            var e = doc.DocumentElement!;
            return new ReportDeposit(e.GetAttribute("Operation"), decimal.Parse(e.GetAttribute("CashAmt"), CultureInfo.InvariantCulture), e.GetAttribute("ChangeDateTime"));
        }).ToList();
        return (invoices, deposits);
    }

    private static string Csv(string v) => v.IndexOfAny([';', '"', '\n', '\r']) >= 0 ? "\"" + v.Replace("\"", "\"\"", StringComparison.Ordinal) + "\"" : v;

    private static DateTimeOffset Parse(string wire) => DateTimeOffset.Parse(wire, CultureInfo.InvariantCulture);

    /// <summary>The local date as written in the wire value (the offset is the profile's zone).</summary>
    private static DateOnly LocalDay(string wire) => DateOnly.FromDateTime(Parse(wire).DateTime);

    private static bool InRange(string wire, DateOnly from, DateOnly to)
    {
        var d = LocalDay(wire);
        return d >= from && d <= to;
    }
}

public enum JournalKind
{
    Analytic,
    Offline,
    Corrective,
}
