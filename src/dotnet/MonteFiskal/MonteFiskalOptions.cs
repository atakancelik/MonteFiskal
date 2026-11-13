namespace MonteFiskal;

/// <summary>How values of the verification URL are written (Q-06, BR-Q6).</summary>
public enum VerificationUrlEncoding
{
    /// <summary>Each value is percent-encoded (RFC 3986 unreserved characters stay). Default.</summary>
    Percent,

    /// <summary>Values are written as in the specification example, <c>+</c> and <c>:</c> unencoded.</summary>
    None,
}

/// <summary>What happens to text outside printable ASCII (Q-07, BR-T8).</summary>
public enum TextPolicy
{
    /// <summary>Send text unchanged; non-ASCII produces a warning. Default.</summary>
    Unchanged,

    /// <summary>Transliterate Montenegrin letters to ASCII before building the request.</summary>
    Transliterate,
}

/// <summary>How the signature of a CIS response is handled (Q-12, BR-S6).</summary>
public enum ResponseSignaturePolicy
{
    /// <summary>Verify when present; a failure is logged as a warning and the result is kept. Default.</summary>
    Verify,

    /// <summary>A missing or invalid signature turns the response into a malformed response.</summary>
    Require,

    /// <summary>Do not look at the response signature.</summary>
    Ignore,
}

/// <summary>Format of date-time values (Q-23).</summary>
public enum DateTimeFormat
{
    /// <summary>Local time of the profile's time zone with its offset, for example <c>+01:00</c>. Default.</summary>
    LocalOffset,

    /// <summary>UTC with <c>Z</c>.</summary>
    Utc,
}

/// <summary>What to do when CIS answers that an IKOF was already registered (Q-09).</summary>
public enum DuplicateIicHandling
{
    /// <summary>Mark the invoice rejected and surface it to a person. Default.</summary>
    Reject,

    /// <summary>Treat the invoice as fiscalized without a JIKR and record the fault.</summary>
    AcceptWithoutFic,
}

/// <summary>Scope of the invoice ordinal sequence (BR-N3).</summary>
public enum SequenceScope
{
    /// <summary>One sequence per TCR and calendar year. Default.</summary>
    TcrYear,

    /// <summary>One sequence per business unit and calendar year.</summary>
    BusinessUnitYear,
}

/// <summary>Options with one switch for each question that only efitest can settle.</summary>
public sealed record MonteFiskalOptions
{
    /// <summary>Overrides the service endpoint (BR-T1). Production hosts are refused by tests.</summary>
    public Uri? ServiceEndpointOverride { get; init; }

    /// <summary>Overrides the verification URL base (BR-Q3, BR-Q4).</summary>
    public Uri? VerificationBaseOverride { get; init; }

    public VerificationUrlEncoding VerificationUrlEncoding { get; init; } = VerificationUrlEncoding.Percent;
    public TextPolicy Text { get; init; } = TextPolicy.Unchanged;
    public ResponseSignaturePolicy ResponseSignature { get; init; } = ResponseSignaturePolicy.Verify;
    public DateTimeFormat DateTimeFormat { get; init; } = DateTimeFormat.LocalOffset;
    public DuplicateIicHandling DuplicateIic { get; init; } = DuplicateIicHandling.Reject;
    public SequenceScope SequenceScope { get; init; } = SequenceScope.TcrYear;

    /// <summary>Decimals of item amounts (Q-14); 2 to 4, default 4.</summary>
    public int ItemDecimals { get; init; } = 4;

    /// <summary>Send the <c>SOAPAction</c> HTTP header (Q-22). Default true.</summary>
    public bool SendSoapAction { get; init; } = true;

    /// <summary>Banknote total at or above which no invoice is issued (Q-15, BR-A8). Default EUR 10,000.</summary>
    public decimal CashLimit { get; init; } = 10_000m;

    /// <summary>Register the day's <c>INITIAL</c> deposit of 0.00 before the first cash invoice (BR-D5). Default true.</summary>
    public bool AutoInitialDeposit { get; init; } = true;

    /// <summary>Code 56 (INITIAL already registered) counts as success for the automatic deposit (Q-17). Default true.</summary>
    public bool TreatInitialAlreadyRegisteredAsSuccess { get; init; } = true;

    /// <summary>Timeout of one send attempt (BR-T6).</summary>
    public TimeSpan SendTimeout { get; init; } = TimeSpan.FromSeconds(15);

    /// <summary>Deadline for a late delivery (BR-O12).</summary>
    public TimeSpan DeliveryDeadline { get; init; } = TimeSpan.FromHours(48);

    /// <summary>Thumbprints of certificates trusted to sign responses (Q-12). Empty: any certificate in the signature.</summary>
    public IReadOnlyList<string> TrustedResponseCertificates { get; init; } = [];
}
