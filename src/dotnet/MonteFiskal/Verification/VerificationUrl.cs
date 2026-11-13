using System.Text;
using MonteFiskal.Model;

namespace MonteFiskal.Verification;

/// <summary>Verification URL printed as QR code (BR-Q1 to BR-Q6).</summary>
public static class VerificationUrl
{
    /// <summary>BR-Q3.</summary>
    public const string TestBase = "https://efitest.tax.gov.me/ic/#/verify";

    /// <summary>BR-Q4.</summary>
    public const string ProductionBase = "https://mapr.tax.gov.me/ic/#/verify";

    public static string BaseFor(FiscalEnvironment environment) => environment == FiscalEnvironment.Production ? ProductionBase : TestBase;

    /// <summary>BR-Q2: parameters in the order iic, tin, crtd, ord, bu, cr, sw, prc.</summary>
    public static string Build(string baseUrl, string iic, IkofParameters p, VerificationUrlEncoding encoding = VerificationUrlEncoding.Percent)
    {
        ArgumentNullException.ThrowIfNull(p);
        var values = new[]
        {
            ("iic", iic), ("tin", p.IssuerTin), ("crtd", p.IssueDateTime), ("ord", p.Ordinal),
            ("bu", p.BusinessUnit), ("cr", p.Tcr), ("sw", p.Software), ("prc", p.TotalPrice),
        };
        var sb = new StringBuilder(baseUrl).Append('?');
        for (var i = 0; i < values.Length; i++)
        {
            if (i > 0)
            {
                sb.Append('&');
            }
            sb.Append(values[i].Item1).Append('=').Append(encoding == VerificationUrlEncoding.Percent ? Encode(values[i].Item2) : values[i].Item2);
        }
        return sb.ToString();
    }

    /// <summary>Percent-encoding of everything except RFC 3986 unreserved characters (BR-Q6, Q-06 default).</summary>
    public static string Encode(string value)
    {
        var sb = new StringBuilder();
        foreach (var b in Encoding.UTF8.GetBytes(value))
        {
            var c = (char)b;
            if (c is (>= 'A' and <= 'Z') or (>= 'a' and <= 'z') or (>= '0' and <= '9') or '-' or '.' or '_' or '~')
            {
                sb.Append(c);
            }
            else
            {
                sb.Append('%').Append(b.ToString("X2", System.Globalization.CultureInfo.InvariantCulture));
            }
        }
        return sb.ToString();
    }
}
