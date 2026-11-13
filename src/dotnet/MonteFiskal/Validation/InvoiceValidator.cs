using MonteFiskal.Calculation;
using MonteFiskal.Model;
using MonteFiskal.Text;
using MonteFiskal.Wire;

namespace MonteFiskal.Validation;

/// <summary>
/// Local rules for invoices. Every check quotes its rule ID. Rules marked UNVERIFIED in the analysis produce
/// warnings, verified rules produce errors. The schema check (<c>SCHEMA</c>) runs only when no business rule failed.
/// </summary>
public static class InvoiceValidator
{
    private static readonly HashSet<PaymentMethod> CashAllowed = [PaymentMethod.Banknote, PaymentMethod.Card, PaymentMethod.OtherCash, PaymentMethod.Order];

    private static readonly HashSet<PaymentMethod> NonCashAllowed =
    [
        PaymentMethod.BusinessCard, PaymentMethod.SingleVoucher, PaymentMethod.Company, PaymentMethod.Advance,
        PaymentMethod.Account, PaymentMethod.Factoring, PaymentMethod.Other, PaymentMethod.Order,
    ];

    /// <summary>Validates a draft. <paramref name="now"/> is the clock used for <c>IssuedAt</c> when the draft has none.</summary>
    public static ValidationResult Validate(FiscalProfile profile, InvoiceDraft draft, DateTimeOffset now, MonteFiskalOptions? options = null, IRoundingPolicy? rounding = null)
    {
        ArgumentNullException.ThrowIfNull(profile);
        ArgumentNullException.ThrowIfNull(draft);
        options ??= new MonteFiskalOptions();
        var f = new FindingList();
        var issuedAt = draft.IssuedAt ?? now;
        var type = RequestBuilder.DeriveTypeOfInvoice(draft);

        // BR-K3: payment methods allowed for the invoice type.
        for (var i = 0; i < draft.Payments.Count; i++)
        {
            var p = draft.Payments[i];
            var allowed = type == TypeOfInvoice.Cash ? CashAllowed : NonCashAllowed;
            if (!allowed.Contains(p.Method))
            {
                f.Error("BR-K3", $"PayMethods/PayMethod[{i + 1}]", $"{WireName.Of(p.Method)} is not allowed on a {WireName.Of(type)} invoice.");
            }
            if (p.Method == PaymentMethod.BusinessCard && draft.Buyer?.IdType != IdType.Tin)
            {
                // BR-K3, Q-29: a business card belongs to a taxpayer; without a buyer TIN the choice is doubtful.
                f.Warn("BR-K3", $"PayMethods/PayMethod[{i + 1}]", "BUSINESSCARD without a buyer identified by TIN.");
            }
            // BR-K5: attributes that belong to a specific payment method.
            if (p.Method == PaymentMethod.Advance && string.IsNullOrEmpty(p.AdvanceIic))
            {
                f.Error("BR-K5", $"PayMethods/PayMethod[{i + 1}]/@AdvIIC", "ADVANCE needs the IKOF of the advance invoice.");
            }
            if (p.Method == PaymentMethod.SingleVoucher && (p.Vouchers.Count == 0 || string.IsNullOrEmpty(p.AdvanceIic)))
            {
                f.Error("BR-K5", $"PayMethods/PayMethod[{i + 1}]", "SVOUCHER needs the voucher numbers and the IKOF of the advance invoice.");
            }
            if (!string.IsNullOrEmpty(p.CompanyCard) && p.Method != PaymentMethod.Company)
            {
                f.Error("BR-K5", $"PayMethods/PayMethod[{i + 1}]/@CompCard", "CompCard is given only for COMPANY.");
            }
            if (p.Vouchers.Count > 0 && p.Method != PaymentMethod.SingleVoucher)
            {
                f.Error("BR-K5", $"PayMethods/PayMethod[{i + 1}]/Vouchers", "Vouchers are given only for SVOUCHER.");
            }
            if (!string.IsNullOrEmpty(p.AdvanceIic) && p.Method is not (PaymentMethod.Advance or PaymentMethod.SingleVoucher))
            {
                f.Error("BR-K5", $"PayMethods/PayMethod[{i + 1}]/@AdvIIC", "AdvIIC is given only for ADVANCE and SVOUCHER.");
            }
            if (!string.IsNullOrEmpty(p.BankAccount) && draft.Kind != InvoiceKind.CreditNote)
            {
                f.Warn("BR-K5", $"PayMethods/PayMethod[{i + 1}]/@BankAcc", "BankAcc is meant for the pay-out of a credit note.");
            }
        }

        // BR-K1: a cash invoice paid only with OTHER-CASH (UNVERIFIED).
        if (type == TypeOfInvoice.Cash && draft.Payments.Count > 0 && draft.Payments.All(p => p.Method == PaymentMethod.OtherCash))
        {
            f.Warn("BR-K1", "PayMethods", "Cash invoice paid only with OTHER-CASH.");
        }

        // BR-K6: references and approvals of report-like invoice types.
        if (draft.Kind is InvoiceKind.Summary or InvoiceKind.Periodical or InvoiceKind.CreditNote)
        {
            if (draft.IicRefs.Count == 0)
            {
                f.Error("BR-K6", "IICRefs", $"{WireName.Of(draft.Kind)} needs IICRefs.");
            }
            if (draft.Kind == InvoiceKind.CreditNote && draft.Approvals.Count == 0)
            {
                f.Error("BR-K6", "Approvals", "CREDIT_NOTE needs Approvals (BR-K18).");
            }
            f.Warn("BR-K6", "@InvType", $"{WireName.Of(draft.Kind)} is supported but not yet confirmed on efitest (Q-02).");
        }

        // BR-K8: periodic invoice needs a buyer and a tax period.
        if (draft.Kind == InvoiceKind.Periodical)
        {
            if (draft.Buyer is null)
            {
                f.Error("BR-K8", "Buyer", "PERIODICAL needs a buyer.");
            }
            if (string.IsNullOrEmpty(draft.TaxPeriod))
            {
                f.Error("BR-K8", "@TaxPeriod", "PERIODICAL needs the tax period (MM/YYYY).");
            }
        }

        // BR-K10: self-issued invoice needs a buyer identified by TIN; who signs is UNVERIFIED (Q-11).
        if (draft.SelfIssuing is not null)
        {
            if (draft.Buyer?.IdType != IdType.Tin)
            {
                f.Error("BR-K10", "Buyer/@IDType", "A self-issued invoice needs the buyer identified by TIN (error 54).");
            }
            f.Warn("BR-K10", "@TypeOfSelfIss", "Self-issued invoices are not yet confirmed on efitest (Q-11).");
        }

        // BR-K11: reverse charge must not be sent as true.
        if (draft.ReverseCharge == true)
        {
            f.Error("BR-K11", "@IsReverseCharge", "Reverse charge is not applied; send false or omit it.");
        }

        // BR-C4: corrective invoices carry the reference to the original, and only they do.
        if (draft.Kind == InvoiceKind.Corrective && draft.Correction is null)
        {
            f.Error("BR-C4", "CorrectiveInv", "CORRECTIVE needs CorrectiveInv with the IKOF of the original.");
        }
        if (draft.Kind != InvoiceKind.Corrective && draft.Correction is not null)
        {
            f.Error("BR-C4", "CorrectiveInv", "CorrectiveInv is given only on a CORRECTIVE invoice.");
        }
        if (draft.Correction is { Type: CorrectionType.ErrorCorrective } c && issuedAt - c.IssuedAt > TimeSpan.FromDays(8))
        {
            f.Warn("BR-C4", "CorrectiveInv/@IssueDateTime", "ERROR_CORRECTIVE is meant to be issued within 8 days of the original.");
        }

        // BR-V6, BR-V3, BR-A2: VAT data and signs of each line.
        for (var i = 0; i < draft.Lines.Count; i++)
        {
            var l = draft.Lines[i];
            var path = $"Items/I[{i + 1}]";
            if (!profile.IssuerInVat && (l.VatRate is not null || !string.IsNullOrEmpty(l.Exemption)))
            {
                f.Error("BR-V6", path + "/@VR", "A seller outside the VAT system sends no VAT rate or exemption.");
            }
            if (profile.IssuerInVat && (l.VatRate is null) == string.IsNullOrEmpty(l.Exemption))
            {
                f.Error("BR-V3", path + "/@VR", "A line of a VAT seller has either a VAT rate or an exemption code.");
            }
            if (l.UnitPrice < 0m)
            {
                f.Error("BR-A2", path + "/@UPB", "Unit prices are never negative; corrections use negative quantities.");
            }
            if (l.VatRate < 0m)
            {
                f.Error("BR-A2", path + "/@VR", "VAT rates are never negative.");
            }
            if (l.Rebate is < 0m or > 100m)
            {
                f.Error("BR-A2", path + "/@R", "A rebate is between 0 and 100 percent.");
            }
        }

        // BR-T8: characters of every text field.
        foreach (var (path, text) in Texts(profile, draft))
        {
            if (TextRules.HasControlCharacter(text))
            {
                f.Error("BR-T8", path, "Control characters are not allowed.");
            }
            else if (options.Text == TextPolicy.Unchanged && TextRules.HasNonAscii(text))
            {
                f.Warn("BR-T8", path, "Text outside ASCII is sent unchanged; whether CIS accepts it is open (Q-07).");
            }
        }

        var facts = RequestBuilder.Facts(profile, draft with { IssuedAt = issuedAt }, 1, options, rounding);
        var payments = RequestBuilder.PaymentsWithAmounts(draft, facts.Totals);

        // BR-A8, Q-15: physical cash of EUR 10,000 or more must go through a bank account.
        var banknotes = payments.Where(p => p.Method == PaymentMethod.Banknote).Sum(p => p.Amount ?? 0m);
        if (banknotes >= options.CashLimit)
        {
            f.Error("BR-A8", "PayMethods", $"Cash payments of EUR {options.CashLimit:0.00} or more are not allowed.");
        }

        // BR-K4: the payments should add up to the total (UNVERIFIED).
        if (payments.Count > 0 && payments.Sum(p => p.Amount ?? 0m) != facts.Totals.Total)
        {
            f.Warn("BR-K4", "PayMethods", "The payment amounts do not add up to the invoice total.");
        }

        if (!f.HasErrors)
        {
            // BR-O10: schema-invalid data is a blocking error; checked against the bundled official XSD.
            var xml = RequestBuilder.InvoiceRequest(
                RequestBuilder.Invoice(profile, draft with { IssuedAt = issuedAt }, facts, new IssueValues(1, new string('0', 32), new string('0', 512)), options).ToString(),
                new RequestHeader(new Guid("00000000-0000-4000-8000-000000000000"), now), profile.TimeZone, options);
            foreach (var e in SchemaSet.ValidateUnsigned(xml))
            {
                f.Error("SCHEMA", "RegisterInvoiceRequest", e);
            }
        }
        return f.Result();
    }

    internal static IEnumerable<(string Path, string? Text)> Texts(FiscalProfile p, InvoiceDraft d)
    {
        yield return ("Seller/@Name", p.IssuerName);
        yield return ("Seller/@Address", p.IssuerAddress);
        yield return ("Seller/@Town", p.IssuerTown);
        if (d.Buyer is { } b)
        {
            yield return ("Buyer/@Name", b.Name);
            yield return ("Buyer/@Address", b.Address);
            yield return ("Buyer/@Town", b.Town);
            yield return ("Buyer/@IDNum", b.IdNum);
        }
        for (var i = 0; i < d.Lines.Count; i++)
        {
            yield return ($"Items/I[{i + 1}]/@N", d.Lines[i].Name);
            yield return ($"Items/I[{i + 1}]/@U", d.Lines[i].Unit);
            yield return ($"Items/I[{i + 1}]/@C", d.Lines[i].Code);
        }
        yield return ("@Note", d.Note);
    }
}
