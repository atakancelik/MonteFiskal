using System.Security.Cryptography;
using System.Text;

namespace MonteFiskal;

/// <summary>The seven values of the IKOF (BR-I1 to BR-I4), already formatted as sent.</summary>
public sealed record IkofParameters(
    string IssuerTin,
    string IssueDateTime,
    string Ordinal,
    string BusinessUnit,
    string Tcr,
    string Software,
    string TotalPrice);

/// <summary>Result of computing or re-computing an IKOF (BR-I6, BR-X4).</summary>
public sealed record IkofCheck(string Concatenation, string Iic, string IicSignature);

/// <summary>
/// IKOF (protective code of the issuer): RSA-SHA256 PKCS#1 v1.5 signature of the concatenated values, hex upper
/// case (<c>IICSignature</c>), and the MD5 of the signature bytes, hex upper case (<c>IIC</c>). D-01 §4.3.2.
/// </summary>
public static class Ikof
{
    /// <summary>BR-I1, BR-I2: the values joined with <c>|</c> in the fixed order.</summary>
    public static string Concatenate(string tin, string issueDateTime, string ordinal, string businessUnit, string tcr, string software, string totalPrice) =>
        string.Join('|', tin, issueDateTime, ordinal, businessUnit, tcr, software, totalPrice);

    public static string Concatenate(IkofParameters p) =>
        Concatenate(p.IssuerTin, p.IssueDateTime, p.Ordinal, p.BusinessUnit, p.Tcr, p.Software, p.TotalPrice);

    /// <summary>Signs the concatenation with the taxpayer's key. Deterministic (BR-I7).</summary>
    public static IkofCheck Compute(string concatenation, RSA key)
    {
        ArgumentNullException.ThrowIfNull(key);
        var signature = key.SignData(Encoding.UTF8.GetBytes(concatenation), HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        return new IkofCheck(concatenation, IicFromSignature(signature), Convert.ToHexString(signature));
    }

    public static IkofCheck Compute(IkofParameters parameters, RSA key) => Compute(Concatenate(parameters), key);

    /// <summary>BR-I3: MD5 of the signature bytes, upper-case hex.</summary>
    public static string IicFromSignature(byte[] signature) => Convert.ToHexString(MD5.HashData(signature));

    /// <summary>Verifies an <c>IICSignature</c> with a public key (used for the specification example).</summary>
    public static bool Verify(string concatenation, string signatureHex, RSA publicKey)
    {
        ArgumentNullException.ThrowIfNull(publicKey);
        byte[] sig;
        try
        {
            sig = Convert.FromHexString(signatureHex);
        }
        catch (FormatException)
        {
            return false;
        }
        return publicKey.VerifyData(Encoding.UTF8.GetBytes(concatenation), sig, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
    }
}
