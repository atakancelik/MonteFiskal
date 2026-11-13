using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;

namespace MonteFiskal.Security;

/// <summary>Supplies the signing certificate with its private key (BR-S4, BR-S7).</summary>
public interface ICertificateProvider
{
    /// <summary>Returns the certificate; it must have an RSA private key.</summary>
    X509Certificate2 GetCertificate();
}

/// <summary>A certificate that is already loaded, for example from a key store or a test factory.</summary>
public sealed class StaticCertificateProvider(X509Certificate2 certificate) : ICertificateProvider
{
    public X509Certificate2 GetCertificate() => certificate;
}

/// <summary>
/// Loads a PKCS#12 file. The password is read from an environment variable or a file, never from configuration
/// that is checked in. The file path should be outside the web root and outside the repository.
/// </summary>
public sealed class Pkcs12CertificateProvider : ICertificateProvider
{
    private readonly Lazy<X509Certificate2> _certificate;

    public Pkcs12CertificateProvider(string path, Func<string?> password)
    {
        ArgumentException.ThrowIfNullOrEmpty(path);
        ArgumentNullException.ThrowIfNull(password);
        _certificate = new Lazy<X509Certificate2>(() => Load(path, password()));
    }

    /// <summary>Password from an environment variable (preferred).</summary>
    public static Pkcs12CertificateProvider FromEnvironment(string path, string passwordVariable) =>
        new(path, () => Environment.GetEnvironmentVariable(passwordVariable));

    /// <summary>Password from a file readable only by the service account.</summary>
    public static Pkcs12CertificateProvider FromPasswordFile(string path, string passwordFile) =>
        new(path, () => File.ReadAllText(passwordFile).TrimEnd('\r', '\n'));

    public X509Certificate2 GetCertificate() => _certificate.Value;

    private static X509Certificate2 Load(string path, string? password)
    {
        if (!File.Exists(path))
        {
            throw new FiscalConfigurationException($"Certificate file not found: {path}");
        }
        try
        {
            return X509CertificateLoader.LoadPkcs12FromFile(path, password, X509KeyStorageFlags.EphemeralKeySet);
        }
        catch (CryptographicException ex)
        {
            // Q-21: legacy PKCS#12 (RC2/3DES) files load on .NET; a failure is almost always a wrong password.
            throw new FiscalConfigurationException("The certificate could not be opened. Check the password and that the file is a PKCS#12 (.p12/.pfx) with a private key.", ex);
        }
    }
}

/// <summary>Checks applied to the signing certificate before use.</summary>
public static class CertificateChecks
{
    /// <summary>Problems that make the certificate unusable, and warnings.</summary>
    public static (IReadOnlyList<string> Errors, IReadOnlyList<string> Warnings) Inspect(X509Certificate2 certificate, string issuerTin, DateTimeOffset now)
    {
        ArgumentNullException.ThrowIfNull(certificate);
        var errors = new List<string>();
        var warnings = new List<string>();
        using var rsa = certificate.GetRSAPublicKey();
        if (rsa is null)
        {
            errors.Add("The certificate has no RSA key.");
        }
        else if (rsa.KeySize < 2048)
        {
            errors.Add($"The RSA key has {rsa.KeySize} bits; at least 2048 are expected.");
        }
        if (!certificate.HasPrivateKey)
        {
            errors.Add("The certificate has no private key.");
        }
        if (now > certificate.NotAfter)
        {
            errors.Add($"The certificate expired on {certificate.NotAfter:yyyy-MM-dd}.");
        }
        else if (now.AddDays(30) > certificate.NotAfter)
        {
            warnings.Add($"The certificate expires on {certificate.NotAfter:yyyy-MM-dd}.");
        }
        if (now < certificate.NotBefore)
        {
            errors.Add($"The certificate is valid only from {certificate.NotBefore:yyyy-MM-dd}.");
        }
        // BR-S5: CIS compares the TIN of the message with the TIN in the certificate (error 37). Where the TIN sits in
        // the subject is not specified, so a mismatch found here is only a warning.
        if (!string.IsNullOrEmpty(issuerTin) && !certificate.Subject.Contains(issuerTin, StringComparison.Ordinal))
        {
            warnings.Add("The issuer TIN was not found in the certificate subject (BR-S5, error 37 if they differ).");
        }
        return (errors, warnings);
    }
}

/// <summary>Missing or inconsistent configuration, or an unusable certificate (architecture §2.4).</summary>
public sealed class FiscalConfigurationException : Exception
{
    public FiscalConfigurationException(string message) : base(message) { }
    public FiscalConfigurationException(string message, Exception inner) : base(message, inner) { }
    public FiscalConfigurationException() { }
}
