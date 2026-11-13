using MonteFiskal.Model;

namespace MonteFiskal;

/// <summary>
/// The taxpayer and device data that every request needs. All codes come from the user's registration on
/// the Tax Administration portal; the library never invents them (architecture §1, principle 6).
/// </summary>
public sealed record FiscalProfile
{
    public FiscalEnvironment Environment { get; init; } = FiscalEnvironment.Test;

    /// <summary>Tax identification number (PIB, 8 digits, or JMB, 13 digits).</summary>
    public required string IssuerTin { get; init; }
    public required string IssuerName { get; init; }
    public string? IssuerAddress { get; init; }
    public string? IssuerTown { get; init; }

    /// <summary>ISO 3166 alpha-3 code, <c>MNE</c> for Montenegro.</summary>
    public string? IssuerCountry { get; init; } = "MNE";

    /// <summary>Must equal the status in the VAT register (BR-V1).</summary>
    public bool IssuerInVat { get; init; }

    /// <summary>Business unit code (<c>BusinUnitCode</c>).</summary>
    public required string BusinessUnit { get; init; }

    /// <summary>TCR code returned by RegisterTCR (BR-R5). May be empty only while the TCR is being registered.</summary>
    public string Tcr { get; init; } = "";

    /// <summary>Software code of the registered software version (BR-R7).</summary>
    public required string Software { get; init; }

    /// <summary>Operator code of the person issuing invoices (BR-K16).</summary>
    public required string Operator { get; init; }

    /// <summary>Maintainer code (BR-R7); needed for a new TCR registration.</summary>
    public string? Maintainer { get; init; }

    /// <summary>IANA time zone for <c>IssueDateTime</c> and the year in the invoice number (BR-N4).</summary>
    public string TimeZone { get; init; } = "Europe/Podgorica";
}
