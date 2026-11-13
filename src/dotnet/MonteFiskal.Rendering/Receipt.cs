using System.Globalization;
using System.Net;
using System.Reflection;
using System.Text;
using System.Text.Json;
using System.Xml;
using MonteFiskal.Outbox;

namespace MonteFiskal.Rendering;

/// <summary>One printed item.</summary>
public sealed record ReceiptLine(string Name, string Unit, string Quantity, string UnitPriceWithVat, string AmountWithVat, string? VatRate, string? Exemption);

/// <summary>One VAT group of the printed summary.</summary>
public sealed record ReceiptVatGroup(string Key, string Base, string? Vat);

/// <summary>Everything printed on a fiscal invoice (BR-P1, BR-P2, BR-P3), read from the stored request.</summary>
public sealed record Receipt
{
    public required string SellerName { get; init; }
    public string? SellerAddress { get; init; }
    public string? SellerTown { get; init; }
    public required string SellerTin { get; init; }
    public string? BuyerName { get; init; }
    public string? BuyerTin { get; init; }
    public required string InvoiceNumber { get; init; }
    public required string IssueDateTime { get; init; }
    public required string InvoiceType { get; init; }
    public required string BusinessUnit { get; init; }
    public required string Tcr { get; init; }
    public required string Software { get; init; }
    public required string Operator { get; init; }
    public IReadOnlyList<ReceiptLine> Lines { get; init; } = [];
    public IReadOnlyList<ReceiptVatGroup> VatGroups { get; init; } = [];
    public IReadOnlyList<(string Method, string Amount)> Payments { get; init; } = [];
    public required string TotalWithoutVat { get; init; }
    public string? TotalVat { get; init; }
    public required string Total { get; init; }
    public string? PayDeadline { get; init; }
    public required string Iic { get; init; }

    /// <summary>JIKR; null while the invoice is not registered (BR-J2).</summary>
    public string? Fic { get; init; }
    public string? CorrectedIic { get; init; }
    public required string VerificationUrl { get; init; }
    public bool TestMode { get; init; }

    /// <summary>Reads a stored invoice request.</summary>
    public static Receipt FromStored(StoredRequest stored, bool testMode)
    {
        ArgumentNullException.ThrowIfNull(stored);
        var doc = new XmlDocument { XmlResolver = null };
        doc.LoadXml(stored.Body);
        var inv = doc.DocumentElement!;
        XmlElement? Child(string name) => inv.ChildNodes.OfType<XmlElement>().FirstOrDefault(e => e.LocalName == name);
        string? A(XmlElement? e, string n) => e is not null && e.HasAttribute(n) ? e.GetAttribute(n) : null;
        var seller = Child("Seller");
        var buyer = Child("Buyer");
        var lines = Child("Items")?.ChildNodes.OfType<XmlElement>().Select(i => new ReceiptLine(
            i.GetAttribute("N"), i.GetAttribute("U"), i.GetAttribute("Q"), i.GetAttribute("UPA"), i.GetAttribute("PA"), A(i, "VR"), A(i, "EX"))).ToList() ?? [];
        var groups = Child("SameTaxes")?.ChildNodes.OfType<XmlElement>().Select(g => new ReceiptVatGroup(
            A(g, "VATRate") ?? A(g, "ExemptFromVAT") ?? "", g.GetAttribute("PriceBefVAT"), A(g, "VATAmt"))).ToList() ?? [];
        var pays = Child("PayMethods")?.ChildNodes.OfType<XmlElement>().Select(p => (p.GetAttribute("Type"), p.GetAttribute("Amt"))).ToList() ?? [];
        return new Receipt
        {
            SellerName = A(seller, "Name") ?? "",
            SellerAddress = A(seller, "Address"),
            SellerTown = A(seller, "Town"),
            SellerTin = A(seller, "IDNum") ?? "",
            BuyerName = A(buyer, "Name"),
            BuyerTin = A(buyer, "IDNum"),
            InvoiceNumber = inv.GetAttribute("InvNum"),
            IssueDateTime = inv.GetAttribute("IssueDateTime"),
            InvoiceType = inv.GetAttribute("InvType"),
            BusinessUnit = inv.GetAttribute("BusinUnitCode"),
            Tcr = inv.GetAttribute("TCRCode"),
            Software = inv.GetAttribute("SoftCode"),
            Operator = inv.GetAttribute("OperatorCode"),
            Lines = lines,
            VatGroups = groups,
            Payments = pays,
            TotalWithoutVat = inv.GetAttribute("TotPriceWoVAT"),
            TotalVat = A(inv, "TotVATAmt"),
            Total = inv.GetAttribute("TotPrice"),
            PayDeadline = A(inv, "PayDeadline"),
            Iic = inv.GetAttribute("IIC"),
            Fic = stored.State == Model.InvoiceState.Fiscalized ? stored.Result : null,
            CorrectedIic = A(Child("CorrectiveInv"), "IICRef"),
            VerificationUrl = stored.VerificationUrl ?? "",
            TestMode = testMode,
        };
    }
}

/// <summary>Printed labels from <c>data/receipt-labels.json</c>.</summary>
public sealed class ReceiptLabels
{
    private static readonly Lazy<JsonDocument> Doc = new(() =>
    {
        using var s = Assembly.GetExecutingAssembly().GetManifestResourceStream("MonteFiskal.Rendering.receipt-labels.json")!;
        return JsonDocument.Parse(s);
    });

    private readonly JsonElement _lang;

    public ReceiptLabels(string language = "me")
    {
        var langs = Doc.Value.RootElement.GetProperty("languages");
        _lang = langs.TryGetProperty(language, out var l) ? l : langs.GetProperty("en");
    }

    public string this[string key] => _lang.GetProperty(key).GetString()!;

    public string Method(string wire) => _lang.GetProperty("methods").TryGetProperty(wire, out var m) ? m.GetString()! : wire;
}

/// <summary>Printable A4 HTML with the QR code (BR-P1, BR-P2, BR-Q8). Themes and shops may restyle it with CSS.</summary>
public static class HtmlReceipt
{
    public static string Render(Receipt r, string language = "me")
    {
        ArgumentNullException.ThrowIfNull(r);
        var t = new ReceiptLabels(language);
        static string E(string? s) => WebUtility.HtmlEncode(s ?? "");
        var sb = new StringBuilder();
        sb.Append("<!doctype html><html lang=\"").Append(E(language)).Append("\"><head><meta charset=\"utf-8\"><title>").Append(E(r.InvoiceNumber)).Append("</title><style>")
          .Append("body{font:12pt/1.4 system-ui,sans-serif;margin:16mm;color:#111}table{border-collapse:collapse;width:100%}td,th{padding:2mm;border-bottom:1px solid #ccc;text-align:left}")
          .Append(".num{text-align:right}.codes td{border:0;padding:0 2mm 0 0;font-size:10pt}.qr{margin-top:8mm}.test{color:#b00;font-weight:700;border:2px solid #b00;padding:2mm;text-align:center}")
          .Append("@page{size:A4;margin:12mm}</style></head><body>");
        if (r.TestMode)
        {
            sb.Append("<p class=\"test\">").Append(E(t["testMode"])).Append("</p>");
        }
        sb.Append("<h1>").Append(E(r.InvoiceType == "CORRECTIVE" ? t["corrective"] : t["fiscalInvoice"])).Append("</h1>");
        sb.Append("<p><strong>").Append(E(r.SellerName)).Append("</strong><br>").Append(E(r.SellerAddress)).Append(' ').Append(E(r.SellerTown))
          .Append("<br>").Append(E(t["tin"])).Append(": ").Append(E(r.SellerTin)).Append("</p>");
        if (r.BuyerName is not null)
        {
            sb.Append("<p>").Append(E(t["buyer"])).Append(": ").Append(E(r.BuyerName)).Append(", ").Append(E(t["tin"])).Append(": ").Append(E(r.BuyerTin)).Append("</p>");
        }
        sb.Append("<table class=\"codes\">");
        void Row(string k, string? v) => sb.Append("<tr><td>").Append(E(t[k])).Append("</td><td>").Append(E(v)).Append("</td></tr>");
        Row("invoiceNumber", r.InvoiceNumber);
        Row("issued", r.IssueDateTime.Replace('T', ' ')[..16]);
        Row("businessUnit", r.BusinessUnit);
        Row("tcr", r.Tcr);
        Row("software", r.Software);
        Row("operator", r.Operator);
        if (r.CorrectedIic is not null)
        {
            Row("reference", r.CorrectedIic);
        }
        sb.Append("</table><table><thead><tr><th>").Append(E(t["item"])).Append("</th><th class=\"num\">").Append(E(t["quantity"])).Append("</th><th class=\"num\">")
          .Append(E(t["unitPrice"])).Append("</th><th class=\"num\">").Append(E(t["vatRate"])).Append("</th><th class=\"num\">").Append(E(t["amount"])).Append("</th></tr></thead><tbody>");
        foreach (var l in r.Lines)
        {
            sb.Append("<tr><td>").Append(E(l.Name)).Append("</td><td class=\"num\">").Append(E(l.Quantity)).Append(' ').Append(E(l.Unit)).Append("</td><td class=\"num\">")
              .Append(E(Money(l.UnitPriceWithVat))).Append("</td><td class=\"num\">").Append(E(l.VatRate ?? l.Exemption ?? "")).Append("</td><td class=\"num\">")
              .Append(E(Money(l.AmountWithVat))).Append("</td></tr>");
        }
        sb.Append("</tbody></table><table>");
        foreach (var g in r.VatGroups)
        {
            sb.Append("<tr><td>").Append(E(t["vat"])).Append(' ').Append(E(g.Key)).Append("</td><td class=\"num\">").Append(E(t["base"])).Append(' ').Append(E(g.Base))
              .Append("</td><td class=\"num\">").Append(E(g.Vat ?? "")).Append("</td></tr>");
        }
        sb.Append("<tr><td>").Append(E(t["totalWithoutVat"])).Append("</td><td></td><td class=\"num\">").Append(E(r.TotalWithoutVat)).Append("</td></tr>");
        if (r.TotalVat is not null)
        {
            sb.Append("<tr><td>").Append(E(t["totalVat"])).Append("</td><td></td><td class=\"num\">").Append(E(r.TotalVat)).Append("</td></tr>");
        }
        sb.Append("<tr><th>").Append(E(t["total"])).Append("</th><td></td><th class=\"num\">").Append(E(r.Total)).Append(" EUR</th></tr>");
        foreach (var (m, a) in r.Payments)
        {
            sb.Append("<tr><td>").Append(E(t["payment"])).Append(": ").Append(E(t.Method(m))).Append("</td><td></td><td class=\"num\">").Append(E(a)).Append("</td></tr>");
        }
        if (r.PayDeadline is not null)
        {
            sb.Append("<tr><td>").Append(E(t["payDeadline"])).Append("</td><td></td><td class=\"num\">").Append(E(r.PayDeadline)).Append("</td></tr>");
        }
        sb.Append("</table><p>").Append(E(t["iic"])).Append(": <code>").Append(E(r.Iic)).Append("</code><br>").Append(E(t["fic"])).Append(": ");
        sb.Append(r.Fic is null ? E(t["notRegistered"]) : "<code>" + E(r.Fic) + "</code>").Append("</p>");
        sb.Append("<div class=\"qr\">").Append(QrImages.Svg(QrCode.Encode(r.VerificationUrl), 30)).Append("<br><a href=\"").Append(E(r.VerificationUrl)).Append("\">")
          .Append(E(t["verify"])).Append("</a></div></body></html>");
        return sb.ToString();
    }

    private static string Money(string s) =>
        decimal.TryParse(s, NumberStyles.Number, CultureInfo.InvariantCulture, out var d) ? MonteFiskal.Calculation.Fmt.Money(d) : s;
}

/// <summary>ESC/POS receipt for 58 mm (32 columns) and 80 mm (48 columns) printers. Text is transliterated to ASCII.</summary>
public static class EscPosReceipt
{
    public static byte[] Render(Receipt r, int paperWidthMm = 80, string language = "me")
    {
        ArgumentNullException.ThrowIfNull(r);
        var cols = paperWidthMm <= 58 ? 32 : 48;
        var t = new ReceiptLabels(language);
        var ms = new MemoryStream();
        void Raw(params byte[] b) => ms.Write(b);
        void Line(string s = "") { var a = Encoding.ASCII.GetBytes(Text.TextRules.Transliterate(s)); ms.Write(a); ms.WriteByte(0x0A); }
        void Pair(string left, string right)
        {
            left = Text.TextRules.Transliterate(left);
            right = Text.TextRules.Transliterate(right);
            var space = Math.Max(1, cols - left.Length - right.Length);
            if (left.Length + right.Length + 1 > cols)
            {
                Line(left);
                Line(new string(' ', Math.Max(0, cols - right.Length)) + right);
            }
            else
            {
                Line(left + new string(' ', space) + right);
            }
        }
        Raw(0x1B, 0x40); // initialize
        Raw(0x1B, 0x61, 1); // center
        if (r.TestMode)
        {
            Line(t["testMode"]);
        }
        Raw(0x1B, 0x45, 1);
        Line(r.SellerName);
        Raw(0x1B, 0x45, 0);
        Line((r.SellerAddress + " " + r.SellerTown).Trim());
        Line(t["tin"] + ": " + r.SellerTin);
        Line(r.InvoiceType == "CORRECTIVE" ? t["corrective"] : t["fiscalInvoice"]);
        Raw(0x1B, 0x61, 0); // left
        Line(new string('-', cols));
        foreach (var l in r.Lines)
        {
            Line(l.Name);
            Pair("  " + l.Quantity + " x " + l.UnitPriceWithVat, l.AmountWithVat);
        }
        Line(new string('-', cols));
        foreach (var g in r.VatGroups)
        {
            Pair(t["vat"] + " " + g.Key + " / " + g.Base, g.Vat ?? "");
        }
        Raw(0x1B, 0x45, 1);
        Pair(t["total"], r.Total + " EUR");
        Raw(0x1B, 0x45, 0);
        foreach (var (m, a) in r.Payments)
        {
            Pair(t.Method(m), a);
        }
        Line(new string('-', cols));
        Line(t["invoiceNumber"] + ": " + r.InvoiceNumber);
        Line(t["issued"] + ": " + r.IssueDateTime.Replace('T', ' ')[..16]);
        Line(t["businessUnit"] + ": " + r.BusinessUnit);
        Line(t["tcr"] + ": " + r.Tcr);
        Line(t["software"] + ": " + r.Software);
        Line(t["operator"] + ": " + r.Operator);
        Line(t["iic"] + ": " + r.Iic);
        Line(t["fic"] + ": " + (r.Fic ?? t["notRegistered"]));
        Raw(0x1B, 0x61, 1);
        var data = Encoding.ASCII.GetBytes(r.VerificationUrl);
        // GS ( k: QR model 2, module size, error correction M (BR-Q5), store, print.
        var moduleSize = (byte)(cols == 32 ? 4 : 6);
        Raw(0x1D, 0x28, 0x6B, 4, 0, 0x31, 0x41, 0x32, 0);
        Raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x43, moduleSize);
        Raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x45, 0x31);
        var len = data.Length + 3;
        Raw(0x1D, 0x28, 0x6B, (byte)(len % 256), (byte)(len / 256), 0x31, 0x50, 0x30);
        ms.Write(data);
        Raw(0x1D, 0x28, 0x6B, 3, 0, 0x31, 0x51, 0x30);
        Line();
        Raw(0x1B, 0x64, 4); // feed
        Raw(0x1D, 0x56, 0x42, 0); // cut
        return ms.ToArray();
    }
}
