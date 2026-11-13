namespace MonteFiskal.Model;

/// <summary>
/// An invoice before it is issued. Field names are the camel-case names used by <c>test-vectors/</c>
/// and by the PHP core. All amounts are <see cref="decimal"/>; nothing is floating point.
/// </summary>
public sealed record InvoiceDraft
{
    public InvoiceKind Kind { get; init; } = InvoiceKind.Invoice;

    /// <summary>Optional. When null it is derived from the payment methods (BR-K3).</summary>
    public TypeOfInvoice? TypeOfInv { get; init; }

    /// <summary>Time of issue; null means the clock of the client.</summary>
    public DateTimeOffset? IssuedAt { get; init; }

    public Party? Buyer { get; init; }
    public IReadOnlyList<InvoiceLine> Lines { get; init; } = [];
    public IReadOnlyList<Payment> Payments { get; init; } = [];
    public SelfIssuingKind? SelfIssuing { get; init; }
    public bool? ReverseCharge { get; init; }
    public string? PayDeadline { get; init; }
    public SupplyPeriod? SupplyPeriod { get; init; }
    public CurrencyInfo? Currency { get; init; }
    public IReadOnlyList<Fee> Fees { get; init; } = [];
    public IReadOnlyList<InvoiceReference> IicRefs { get; init; } = [];
    public CorrectionInfo? Correction { get; init; }
    public BadDebtInfo? BadDebt { get; init; }
    public IReadOnlyList<Approval> Approvals { get; init; } = [];
    public string? Note { get; init; }
    public string? BankAccountNumber { get; init; }
    public decimal? TaxFreeAmount { get; init; }
    public decimal? MarkUpAmount { get; init; }
    public decimal? GoodsExportAmount { get; init; }
    public decimal? TotalToPay { get; init; }
    public string? TaxPeriod { get; init; }
    public string? ParagonBlockNumber { get; init; }

    /// <summary>Totals of a <c>SUMMARY</c>, <c>PERIODICAL</c> or <c>CREDIT_NOTE</c> invoice without items.</summary>
    public SummaryTotals? SummaryTotals { get; init; }
}

/// <summary>Buyer (or, for a self-issued invoice, the seller on whose behalf the buyer issues).</summary>
public sealed record Party
{
    public IdType IdType { get; init; } = IdType.Tin;
    public required string IdNum { get; init; }
    public required string Name { get; init; }
    public string? Address { get; init; }
    public string? Town { get; init; }
    public string? Country { get; init; }
    public string? Tic { get; init; }
}

/// <summary>One invoice line. <see cref="UnitPrice"/> is net unless <see cref="PriceIncludesVat"/> is true (BR-A7).</summary>
public sealed record InvoiceLine
{
    public required string Name { get; init; }
    public string? Code { get; init; }
    public required string Unit { get; init; }
    public decimal Quantity { get; init; }
    public decimal UnitPrice { get; init; }
    public bool PriceIncludesVat { get; init; }

    /// <summary>VAT rate in percent, for example 21. Null for exempt lines and for sellers outside VAT.</summary>
    public decimal? VatRate { get; init; }

    /// <summary>Exemption code such as <c>VAT_CL20</c> (BR-V5).</summary>
    public string? Exemption { get; init; }

    /// <summary>Rebate in percent (0 to 100) that reduces the base (<c>RR="true"</c>, BR-A5).</summary>
    public decimal? Rebate { get; init; }

    public bool? Investment { get; init; }
    public string? VoucherExpiry { get; init; }
    public string? VoucherSerial { get; init; }
}

/// <summary>A payment method with its amount; null amount means the invoice total (or the remainder).</summary>
public sealed record Payment
{
    public PaymentMethod Method { get; init; }
    public decimal? Amount { get; init; }
    public string? CompanyCard { get; init; }
    public IReadOnlyList<string> Vouchers { get; init; } = [];
    public string? AdvanceIic { get; init; }
    public string? BankAccount { get; init; }
}

public sealed record SupplyPeriod
{
    public required string Start { get; init; }
    public required string End { get; init; }
}

public sealed record CurrencyInfo
{
    public required string Code { get; init; }

    /// <summary>EUR per one unit of the foreign currency (BR-K12).</summary>
    public decimal ExchangeRate { get; init; }
}

public sealed record Fee
{
    public FeeType Type { get; init; }
    public decimal Amount { get; init; }
}

/// <summary>Reference to another invoice (<c>IICRefs/IICRef</c>, BR-K6, BR-K7, BR-K18).</summary>
public sealed record InvoiceReference
{
    public required string Iic { get; init; }
    public DateTimeOffset IssuedAt { get; init; }
    public decimal Amount { get; init; }
}

/// <summary>Reference to the corrected invoice (<c>CorrectiveInv</c>, BR-C4).</summary>
public sealed record CorrectionInfo
{
    public required string IicRef { get; init; }
    public DateTimeOffset IssuedAt { get; init; }
    public CorrectionType Type { get; init; } = CorrectionType.Corrective;
}

/// <summary>Reference to an invoice declared uncollectible (<c>BadDebtInv</c>, BR-K13).</summary>
public sealed record BadDebtInfo
{
    public required string IicRef { get; init; }
    public DateTimeOffset IssuedAt { get; init; }
}

/// <summary>One approval of a credit note (BR-K18).</summary>
public sealed record Approval
{
    public decimal TotalAmount { get; init; }
    public decimal? DiscountAmount { get; init; }
    public decimal? ReturnAmount { get; init; }
    public decimal? VatRate { get; init; }
    public decimal? ExemptAmount { get; init; }
    public decimal? VatAmount { get; init; }
}

/// <summary>Totals given directly for invoices without items.</summary>
public sealed record SummaryTotals
{
    public decimal TotalWithoutVat { get; init; }
    public decimal VatAmount { get; init; }
    public decimal Total { get; init; }
    public IReadOnlyList<SummarySameTax> SameTaxes { get; init; } = [];
}

public sealed record SummarySameTax
{
    public decimal? VatRate { get; init; }
    public string? Exemption { get; init; }
    public int NumberOfItems { get; init; }
    public decimal PriceBeforeVat { get; init; }
    public decimal VatAmount { get; init; }
}

/// <summary>A cash deposit (BR-D1, BR-D2).</summary>
public sealed record CashDeposit
{
    public required string IssuerTin { get; init; }
    public required string Tcr { get; init; }
    public DateTimeOffset? ChangeDateTime { get; init; }
    public CashDepositOperation Operation { get; init; }
    public decimal Amount { get; init; }
}

/// <summary>A TCR registration or change (BR-R1 to BR-R4).</summary>
public sealed record TcrRegistration
{
    public required string IssuerTin { get; init; }
    public required string BusinessUnit { get; init; }
    public required string InternalId { get; init; }
    public string? Software { get; init; }
    public string? Maintainer { get; init; }
    public string? ValidFrom { get; init; }
    public string? ValidTo { get; init; }
    public TcrType? Type { get; init; }
}
