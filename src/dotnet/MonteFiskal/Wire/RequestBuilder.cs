using System.Globalization;
using MonteFiskal.Calculation;
using MonteFiskal.Model;
using MonteFiskal.Numbering;

namespace MonteFiskal.Wire;

/// <summary>Per-request values that are not part of the draft.</summary>
public sealed record RequestHeader(Guid Uuid, DateTimeOffset SendDateTime, SubsequentDelivery? SubsequentDelivery = null);

/// <summary>The values of an issued invoice that enter the XML.</summary>
public sealed record IssueValues(long Ordinal, string Iic, string IicSignature);

/// <summary>Formatted values derived while building an invoice; the same strings go into IKOF and QR (BR-I4).</summary>
public sealed record InvoiceFacts(
    string InvNum,
    string IssueDateTime,
    string IicConcat,
    TypeOfInvoice TypeOfInv,
    CalculatedTotals Totals,
    CalculationResult Calculation);

/// <summary>Builds the request elements of the three operations in canonical form (BR-T3, BR-S2).</summary>
public static class RequestBuilder
{
    public const string Namespace = "https://efi.tax.gov.me/fs/schema";

    private static readonly HashSet<PaymentMethod> CashMethods = [PaymentMethod.Banknote, PaymentMethod.Card, PaymentMethod.OtherCash];

    /// <summary>BR-K3: an invoice with a cash method is a cash invoice; otherwise non-cash.</summary>
    public static TypeOfInvoice DeriveTypeOfInvoice(InvoiceDraft draft) =>
        draft.TypeOfInv ?? (draft.Payments.Any(p => CashMethods.Contains(p.Method)) ? TypeOfInvoice.Cash : TypeOfInvoice.NonCash);

    /// <summary>Computes the totals and formatted values that precede the IKOF.</summary>
    public static InvoiceFacts Facts(FiscalProfile profile, InvoiceDraft draft, long ordinal, MonteFiskalOptions? options = null, IRoundingPolicy? rounding = null)
    {
        options ??= new MonteFiskalOptions();
        var calc = (rounding ?? InvoiceCalculator.Default).Calculate(draft.Lines, profile.IssuerInVat, options.ItemDecimals);
        var totals = calc.Totals;
        var sameTaxes = calc.SameTaxes;
        if (draft.Lines.Count == 0 && draft.SummaryTotals is { } st)
        {
            totals = new CalculatedTotals(Fmt.Money(st.TotalWithoutVat), profile.IssuerInVat ? Fmt.Money(st.VatAmount) : null, Fmt.Money(st.Total));
            var groups = new List<IReadOnlyDictionary<string, string>>();
            if (profile.IssuerInVat)
            {
                foreach (var g in st.SameTaxes)
                {
                    var x = new Dictionary<string, string>(StringComparer.Ordinal)
                    {
                        ["NumOfItems"] = g.NumberOfItems.ToString(CultureInfo.InvariantCulture),
                        ["PriceBefVAT"] = Fmt.Money(g.PriceBeforeVat),
                    };
                    if (g.VatRate is { } vr)
                    {
                        x["VATRate"] = Fmt.Money(vr);
                        x["VATAmt"] = Fmt.Money(g.VatAmount);
                    }
                    else
                    {
                        x["ExemptFromVAT"] = g.Exemption ?? "";
                    }
                    groups.Add(x);
                }
            }
            sameTaxes = groups;
        }
        calc = calc with { Totals = totals, SameTaxes = sameTaxes };
        var issuedAt = draft.IssuedAt ?? throw new ArgumentException("IssuedAt must be set before building.", nameof(draft));
        var issued = FiscalTime.Format(issuedAt, profile.TimeZone, options.DateTimeFormat);
        var invNum = InvoiceNumber.Format(profile.BusinessUnit, ordinal, issuedAt, profile.Tcr, profile.TimeZone);
        var concat = Ikof.Concatenate(profile.IssuerTin, issued, ordinal.ToString(CultureInfo.InvariantCulture), profile.BusinessUnit, profile.Tcr, profile.Software, totals.TotPrice);
        return new InvoiceFacts(invNum, issued, concat, DeriveTypeOfInvoice(draft), totals, calc);
    }

    /// <summary>Builds the <c>Invoice</c> element (without namespace declaration; it inherits it from the root).</summary>
    public static XNode Invoice(FiscalProfile profile, InvoiceDraft draft, InvoiceFacts facts, IssueValues issue, MonteFiskalOptions? options = null)
    {
        options ??= new MonteFiskalOptions();
        var tz = profile.TimeZone;
        string T(DateTimeOffset d) => FiscalTime.Format(d, tz, options.DateTimeFormat);
        var totals = facts.Totals;

        var inv = new XNode("Invoice")
            .Attr("InvType", WireName.Of(draft.Kind))
            .Attr("TypeOfInv", WireName.Of(facts.TypeOfInv))
            .Attr("IssueDateTime", facts.IssueDateTime)
            .Attr("InvNum", facts.InvNum)
            .Attr("InvOrdNum", issue.Ordinal.ToString(CultureInfo.InvariantCulture))
            .Attr("TCRCode", profile.Tcr)
            .Attr("IsIssuerInVAT", profile.IssuerInVat ? "true" : "false")
            .Attr("OperatorCode", profile.Operator)
            .Attr("BusinUnitCode", profile.BusinessUnit)
            .Attr("SoftCode", profile.Software)
            .Attr("IIC", issue.Iic)
            .Attr("IICSignature", issue.IicSignature)
            .Attrs(totals.Attributes);
        if (draft.SelfIssuing is { } si)
        {
            inv.Attr("TypeOfSelfIss", WireName.Of(si));
        }
        inv.Attr("TaxFreeAmt", Money(draft.TaxFreeAmount))
           .Attr("MarkUpAmt", Money(draft.MarkUpAmount))
           .Attr("GoodsExAmt", Money(draft.GoodsExportAmount))
           .Attr("TotPriceToPay", Money(draft.TotalToPay));
        if (draft.ReverseCharge is { } rc)
        {
            inv.Attr("IsReverseCharge", rc ? "true" : "false");
        }
        inv.Attr("PayDeadline", draft.PayDeadline)
           .Attr("ParagonBlockNum", draft.ParagonBlockNumber)
           .Attr("TaxPeriod", draft.TaxPeriod)
           .Attr("BankAccNum", draft.BankAccountNumber)
           .Attr("Note", draft.Note);

        // Children in the order of the schema's xs:all group as listed in the catalog.
        if (draft.SupplyPeriod is { } sp)
        {
            inv.Add(new XNode("SupplyDateOrPeriod").Attr("Start", sp.Start).Attr("End", sp.End));
        }
        if (draft.Correction is { } c)
        {
            inv.Add(new XNode("CorrectiveInv").Attr("IICRef", c.IicRef).Attr("IssueDateTime", T(c.IssuedAt)).Attr("Type", WireName.Of(c.Type)));
        }
        var pm = new XNode("PayMethods");
        foreach (var p in PaymentsWithAmounts(draft, totals))
        {
            var x = new XNode("PayMethod").Attr("Type", WireName.Of(p.Method)).Attr("Amt", Fmt.Money(p.Amount!.Value));
            if (!string.IsNullOrEmpty(p.CompanyCard))
            {
                x.Attr("CompCard", p.CompanyCard);
            }
            if (!string.IsNullOrEmpty(p.AdvanceIic))
            {
                x.Attr("AdvIIC", p.AdvanceIic);
            }
            if (!string.IsNullOrEmpty(p.BankAccount))
            {
                x.Attr("BankAcc", p.BankAccount);
            }
            if (p.Vouchers.Count > 0)
            {
                var v = new XNode("Vouchers");
                foreach (var n in p.Vouchers)
                {
                    v.Add(new XNode("Voucher").Attr("Num", n));
                }
                x.Add(v);
            }
            pm.Add(x);
        }
        inv.Add(pm);
        if (draft.Currency is { } cur)
        {
            inv.Add(new XNode("Currency").Attr("Code", cur.Code).Attr("ExRate", Fmt.Plain(cur.ExchangeRate)));
        }
        var seller = new XNode("Seller").Attr("IDType", "TIN").Attr("IDNum", profile.IssuerTin).Attr("Name", profile.IssuerName);
        if (!string.IsNullOrEmpty(profile.IssuerAddress))
        {
            seller.Attr("Address", profile.IssuerAddress);
        }
        if (!string.IsNullOrEmpty(profile.IssuerTown))
        {
            seller.Attr("Town", profile.IssuerTown);
        }
        if (!string.IsNullOrEmpty(profile.IssuerCountry))
        {
            seller.Attr("Country", profile.IssuerCountry);
        }
        inv.Add(seller);
        if (draft.Buyer is { } b)
        {
            var bx = new XNode("Buyer").Attr("IDType", WireName.Of(b.IdType)).Attr("IDNum", b.IdNum).Attr("Name", b.Name);
            if (!string.IsNullOrEmpty(b.Address)) { bx.Attr("Address", b.Address); }
            if (!string.IsNullOrEmpty(b.Town)) { bx.Attr("Town", b.Town); }
            if (!string.IsNullOrEmpty(b.Country)) { bx.Attr("Country", b.Country); }
            if (!string.IsNullOrEmpty(b.Tic)) { bx.Attr("TIC", b.Tic); }
            inv.Add(bx);
        }
        if (facts.Calculation.Lines.Count > 0)
        {
            var items = new XNode("Items");
            foreach (var l in facts.Calculation.Lines)
            {
                items.Add(new XNode("I").Attrs(l.Attributes));
            }
            inv.Add(items);
        }
        if (facts.Calculation.SameTaxes.Count > 0)
        {
            var sts = new XNode("SameTaxes");
            foreach (var g in facts.Calculation.SameTaxes)
            {
                sts.Add(new XNode("SameTax").Attrs(g));
            }
            inv.Add(sts);
        }
        if (draft.Approvals.Count > 0)
        {
            var aps = new XNode("Approvals");
            foreach (var ap in draft.Approvals)
            {
                aps.Add(new XNode("Approval").Attr("TotalAmt", Fmt.Money(ap.TotalAmount)).Attr("DiscountAmt", Money(ap.DiscountAmount))
                    .Attr("ReturnAmt", Money(ap.ReturnAmount)).Attr("VATRate", Money(ap.VatRate)).Attr("ExemptFromVAT", Money(ap.ExemptAmount))
                    .Attr("VATAmt", Money(ap.VatAmount)));
            }
            inv.Add(aps);
        }
        if (draft.Fees.Count > 0)
        {
            var fees = new XNode("Fees");
            foreach (var f in draft.Fees)
            {
                fees.Add(new XNode("Fee").Attr("Type", WireName.Of(f.Type)).Attr("Amt", Fmt.Money(f.Amount)));
            }
            inv.Add(fees);
        }
        if (draft.IicRefs.Count > 0)
        {
            var refs = new XNode("IICRefs");
            foreach (var r in draft.IicRefs)
            {
                refs.Add(new XNode("IICRef").Attr("IIC", r.Iic).Attr("IssueDateTime", T(r.IssuedAt)).Attr("Amount", Fmt.Money(r.Amount)));
            }
            inv.Add(refs);
        }
        if (draft.BadDebt is { } bd)
        {
            inv.Add(new XNode("BadDebtInv").Attr("IICRef", bd.IicRef).Attr("IssueDateTime", T(bd.IssuedAt)));
        }
        return inv;
    }

    /// <summary>Payments with every amount filled in: a single payment without amount takes the remainder of the total.</summary>
    public static IReadOnlyList<Payment> PaymentsWithAmounts(InvoiceDraft draft, CalculatedTotals totals)
    {
        var known = draft.Payments.Where(p => p.Amount is not null).Sum(p => p.Amount!.Value);
        return draft.Payments.Select(p => p.Amount is null ? p with { Amount = totals.Total - known } : p).ToList();
    }

    /// <summary>The header element of a request.</summary>
    public static XNode Header(RequestHeader header, string timeZone, MonteFiskalOptions? options = null)
    {
        var h = new XNode("Header")
            .Attr("UUID", header.Uuid.ToString("D"))
            .Attr("SendDateTime", FiscalTime.Format(header.SendDateTime, timeZone, (options ?? new MonteFiskalOptions()).DateTimeFormat));
        if (header.SubsequentDelivery is { } sd)
        {
            h.Attr("SubseqDelivType", WireName.Of(sd));
        }
        return h;
    }

    /// <summary>Root element with <c>Id="Request"</c> and <c>Version="1"</c> (BR-S2, BR-S8).</summary>
    public static XNode Root(string element, XNode header, XNode body) =>
        new XNode(element, Namespace).Attr("Id", "Request").Attr("Version", "1").Add(header).Add(body);

    /// <summary>Unsigned <c>RegisterInvoiceRequest</c> from a stored <c>Invoice</c> element.</summary>
    public static string InvoiceRequest(string invoiceElement, RequestHeader header, string timeZone, MonteFiskalOptions? options = null)
    {
        var root = new XNode("RegisterInvoiceRequest", Namespace).Attr("Id", "Request").Attr("Version", "1");
        var head = Header(header, timeZone, options).ToString();
        var s = root.ToString();
        var open = s[..(s.IndexOf('>', StringComparison.Ordinal) + 1)];
        return open + head + invoiceElement + "</RegisterInvoiceRequest>";
    }

    /// <summary>The <c>TCR</c> element of <c>RegisterTCRRequest</c>.</summary>
    public static XNode Tcr(TcrRegistration reg)
    {
        var t = new XNode("TCR").Attr("IssuerTIN", reg.IssuerTin).Attr("BusinUnitCode", reg.BusinessUnit).Attr("TCRIntID", reg.InternalId);
        if (!string.IsNullOrEmpty(reg.Software)) { t.Attr("SoftCode", reg.Software); }
        if (!string.IsNullOrEmpty(reg.Maintainer)) { t.Attr("MaintainerCode", reg.Maintainer); }
        if (!string.IsNullOrEmpty(reg.ValidFrom)) { t.Attr("ValidFrom", reg.ValidFrom); }
        if (!string.IsNullOrEmpty(reg.ValidTo)) { t.Attr("ValidTo", reg.ValidTo); }
        if (reg.Type is { } ty) { t.Attr("Type", WireName.Of(ty)); }
        return t;
    }

    /// <summary>The <c>CashDeposit</c> element of <c>RegisterCashDepositRequest</c>.</summary>
    public static XNode Deposit(CashDeposit dep, string timeZone, MonteFiskalOptions? options = null) =>
        new XNode("CashDeposit")
            .Attr("ChangeDateTime", FiscalTime.Format(dep.ChangeDateTime ?? throw new ArgumentException("ChangeDateTime must be set.", nameof(dep)), timeZone, (options ?? new MonteFiskalOptions()).DateTimeFormat))
            .Attr("Operation", WireName.Of(dep.Operation))
            .Attr("CashAmt", Fmt.Money(dep.Amount))
            .Attr("TCRCode", dep.Tcr)
            .Attr("IssuerTIN", dep.IssuerTin);

    private static string? Money(decimal? v) => v is { } x ? Fmt.Money(x) : null;
}
