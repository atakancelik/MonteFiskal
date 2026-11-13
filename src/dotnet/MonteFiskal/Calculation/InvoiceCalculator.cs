using MonteFiskal.Model;

namespace MonteFiskal.Calculation;

/// <summary>Wire attributes of one item (<c>Items/I</c>) and the unformatted values behind them.</summary>
public sealed record CalculatedLine(
    IReadOnlyDictionary<string, string> Attributes,
    decimal PriceBeforeVat,
    decimal VatAmount,
    decimal PriceAfterVat,
    string? GroupKey);

/// <summary>Invoice totals as wire strings.</summary>
public sealed record CalculatedTotals(string TotPriceWoVat, string? TotVatAmt, string TotPrice)
{
    public decimal Total => decimal.Parse(TotPrice, System.Globalization.CultureInfo.InvariantCulture);

    public IReadOnlyDictionary<string, string> Attributes
    {
        get
        {
            var d = new Dictionary<string, string>(StringComparer.Ordinal) { ["TotPriceWoVAT"] = TotPriceWoVat, ["TotPrice"] = TotPrice };
            if (TotVatAmt is not null)
            {
                d["TotVATAmt"] = TotVatAmt;
            }
            return d;
        }
    }
}

/// <summary>Result of the calculation: items, totals and VAT groups (<c>SameTaxes</c>).</summary>
public sealed record CalculationResult(
    IReadOnlyList<CalculatedLine> Lines,
    CalculatedTotals Totals,
    IReadOnlyList<IReadOnlyDictionary<string, string>> SameTaxes);

/// <summary>Rounding and calculation policy (BR-A5 to BR-A7, Q-14). The default is <see cref="InvoiceCalculator"/>.</summary>
public interface IRoundingPolicy
{
    CalculationResult Calculate(IReadOnlyList<InvoiceLine> lines, bool issuerInVat, int itemDecimals);
}

/// <summary>
/// Default calculation. Reproduces the production behaviour described in BR-A5: item amounts with four
/// decimals, rounded half away from zero, VA = PA − PB; two decimals only for groups and totals.
/// For gross-priced lines the price with VAT is authoritative (BR-A7), so the total equals what was charged.
/// </summary>
public sealed class InvoiceCalculator : IRoundingPolicy
{
    public static InvoiceCalculator Default { get; } = new();

    public CalculationResult Calculate(IReadOnlyList<InvoiceLine> lines, bool issuerInVat, int itemDecimals = 4)
    {
        ArgumentNullException.ThrowIfNull(lines);
        if (itemDecimals is < 2 or > 4)
        {
            throw new ArgumentOutOfRangeException(nameof(itemDecimals), "BR-A1: item amounts have two to four decimals.");
        }
        var allGross = lines.Count > 0 && lines.All(l => l.PriceIncludesVat);
        var outLines = new List<CalculatedLine>(lines.Count);
        foreach (var l in lines)
        {
            outLines.Add(CalculateLine(l, issuerInVat, itemDecimals));
        }

        var spb = outLines.Sum(x => x.PriceBeforeVat);
        var sva = outLines.Sum(x => x.VatAmount);
        var spa = outLines.Sum(x => x.PriceAfterVat);
        CalculatedTotals totals;
        if (!issuerInVat)
        {
            // BR-V6: outside VAT the prices before and after VAT are equal and there is no VAT total.
            totals = new CalculatedTotals(Fmt.Money(spb), null, Fmt.Money(spb));
        }
        else if (allGross)
        {
            // BR-A7: the charged total is authoritative for gross-priced invoices.
            var tp = Fmt.Round(spa, 2);
            var tv = Fmt.Round(sva, 2);
            totals = new CalculatedTotals(Fmt.Money(tp - tv), Fmt.Money(tv), Fmt.Money(tp));
        }
        else
        {
            // BR-A5, BR-A6: round to two decimals only at total level.
            var tw = Fmt.Round(spb, 2);
            var tv = Fmt.Round(sva, 2);
            totals = new CalculatedTotals(Fmt.Money(tw), Fmt.Money(tv), Fmt.Money(tw + tv));
        }

        var groups = new List<IReadOnlyDictionary<string, string>>();
        if (issuerInVat)
        {
            // BR-V3: one SameTax per rate or exemption, in order of first appearance.
            foreach (var key in outLines.Select(x => x.GroupKey).Distinct())
            {
                var g = outLines.Where(x => x.GroupKey == key).ToList();
                var st = new Dictionary<string, string>(StringComparer.Ordinal)
                {
                    ["NumOfItems"] = g.Count.ToString(System.Globalization.CultureInfo.InvariantCulture),
                    ["PriceBefVAT"] = Fmt.Money(g.Sum(x => x.PriceBeforeVat)),
                };
                if (key is not null && key.StartsWith("VR:", StringComparison.Ordinal))
                {
                    st["VATRate"] = key[3..];
                    st["VATAmt"] = Fmt.Money(g.Sum(x => x.VatAmount));
                }
                else if (key is not null)
                {
                    st["ExemptFromVAT"] = key[3..];
                }
                groups.Add(st);
            }
        }
        return new CalculationResult(outLines, totals, groups);
    }

    private static CalculatedLine CalculateLine(InvoiceLine l, bool inVat, int dec)
    {
        var q = l.Quantity;
        var p = l.UnitPrice;
        var f = l.Rebate is { } r ? 1m - (r / 100m) : 1m;
        var vr = l.VatRate;
        decimal upb, upa, pb, pa;
        decimal? va;
        if (!inVat || vr is null)
        {
            upb = Fmt.Round(p, 4);
            upa = upb;
            pb = Fmt.Round(upb * q * f, 4);
            pa = pb;
            va = null;
        }
        else if (!l.PriceIncludesVat)
        {
            // BR-A5: UPA = UPB × (1 + VR/100), PB = UPB × Q, PA = UPA × Q, VA = PA − PB.
            upb = Fmt.Round(p, 4);
            upa = Fmt.Round(upb * (1m + (vr.Value / 100m)), 4);
            pb = Fmt.Round(upb * q * f, 4);
            pa = Fmt.Round(upa * q * f, 4);
            va = pa - pb;
        }
        else
        {
            // BR-A7: from the gross price; the line total with VAT is authoritative.
            upa = Fmt.Round(p, 4);
            upb = Fmt.Round(upa / (1m + (vr.Value / 100m)), 4);
            pa = Fmt.Round(upa * q * f, 4);
            pb = Fmt.Round(pa / (1m + (vr.Value / 100m)), 4);
            va = pa - pb;
        }

        var a = new Dictionary<string, string>(StringComparer.Ordinal) { ["N"] = l.Name };
        if (!string.IsNullOrEmpty(l.Code))
        {
            a["C"] = l.Code;
        }
        a["U"] = l.Unit;
        a["Q"] = Fmt.Quantity(q);
        a["UPB"] = Fmt.Fixed(upb, dec);
        a["UPA"] = Fmt.Fixed(upa, dec);
        if (l.Rebate is { } rebate)
        {
            a["R"] = Fmt.Money(rebate);
            // BR-A5: RR states that the rebate reduces the base; only "true" is supported (see docs/STATUS.md).
            a["RR"] = "true";
        }
        a["PB"] = Fmt.Fixed(pb, dec);
        string? group = null;
        if (inVat && vr is not null)
        {
            a["VR"] = Fmt.Money(vr.Value);
            a["VA"] = Fmt.Fixed(va!.Value, dec);
            group = "VR:" + Fmt.Money(vr.Value);
        }
        else if (inVat)
        {
            group = "EX:" + l.Exemption;
        }
        if (l.Investment == true)
        {
            a["IN"] = "true";
        }
        a["PA"] = Fmt.Fixed(pa, dec);
        if (inVat && !string.IsNullOrEmpty(l.Exemption))
        {
            a["EX"] = l.Exemption;
        }
        if (!string.IsNullOrEmpty(l.VoucherExpiry))
        {
            a["VD"] = l.VoucherExpiry;
        }
        if (!string.IsNullOrEmpty(l.VoucherSerial))
        {
            a["VSN"] = l.VoucherSerial;
        }
        return new CalculatedLine(a, pb, va ?? 0m, pa, group);
    }
}
