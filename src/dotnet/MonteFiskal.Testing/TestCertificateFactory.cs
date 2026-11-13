using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;

namespace MonteFiskal.Testing;

/// <summary>
/// Creates throw-away self-signed certificates at run time. Nothing is written to disk and no certificate is
/// ever committed (analysis Q-19). These certificates are not accepted by the real service.
/// </summary>
public static class TestCertificateFactory
{
    /// <summary>A 2048-bit RSA certificate whose subject carries the TIN, valid for one year.</summary>
    public static X509Certificate2 Create(string tin = "12345678", string commonName = "MonteFiskal Test Only", int keySize = 2048, DateTimeOffset? notBefore = null)
    {
        using var rsa = RSA.Create(keySize);
        var subject = new X500DistinguishedName($"CN={commonName}, SERIALNUMBER={tin}, O=Test Only, C=ME");
        var req = new CertificateRequest(subject, rsa, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        req.CertificateExtensions.Add(new X509KeyUsageExtension(X509KeyUsageFlags.DigitalSignature | X509KeyUsageFlags.NonRepudiation, true));
        var from = notBefore ?? DateTimeOffset.UtcNow.AddDays(-1);
        using var cert = req.CreateSelfSigned(from, from.AddYears(1));
        // Round-trip through PKCS#12 in memory so that the private key is usable on every platform.
        var pwd = Convert.ToHexString(RandomNumberGenerator.GetBytes(16));
        return X509CertificateLoader.LoadPkcs12(cert.Export(X509ContentType.Pkcs12, pwd), pwd, X509KeyStorageFlags.Exportable | X509KeyStorageFlags.EphemeralKeySet);
    }

    /// <summary>PEM of the private key (PKCS#8) and the certificate, for handing a run-time key to another process.</summary>
    public static (string PrivateKeyPem, string CertificatePem) ExportPem(X509Certificate2 certificate)
    {
        ArgumentNullException.ThrowIfNull(certificate);
        using var key = certificate.GetRSAPrivateKey() ?? throw new InvalidOperationException("No private key.");
        return (key.ExportPkcs8PrivateKeyPem(), certificate.ExportCertificatePem());
    }
}
