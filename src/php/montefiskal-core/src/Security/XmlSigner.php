<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

/**
 * Enveloped XML-DSig with the fixed profile of BR-S1 to BR-S4: one reference to #Request, transforms
 * enveloped-signature and exclusive C14N, RSA-SHA256, SHA-256 digest, one X509Certificate. The unsigned element
 * written by XNode is already canonical, so the digest is taken over its bytes and the output is byte-controlled.
 * The same bytes as the .NET XmlSigner (checked by the cross-signature test).
 */
final class XmlSigner
{
    public const DSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';

    public static function sign(string $canonicalRequest, Certificate $certificate, string $referenceId = 'Request'): string
    {
        $digest = base64_encode(hash('sha256', $canonicalRequest, true));
        $signedInfo = self::signedInfo($digest, true, $referenceId);
        if (!openssl_sign($signedInfo, $signature, $certificate->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new FiscalConfigurationException('XML signing failed: ' . (openssl_error_string() ?: 'unknown OpenSSL error'));
        }
        $element = '<Signature xmlns="' . self::DSIG_NS . '">' . self::signedInfo($digest, false, $referenceId)
            . '<SignatureValue>' . base64_encode($signature) . '</SignatureValue><KeyInfo><X509Data><X509Certificate>'
            . base64_encode($certificate->der()) . '</X509Certificate></X509Data></KeyInfo></Signature>';
        $close = strrpos($canonicalRequest, '</');
        return substr($canonicalRequest, 0, $close) . $element . substr($canonicalRequest, $close);
    }

    /** The canonical SignedInfo. Inside the document the namespace is inherited from Signature. */
    public static function signedInfo(string $digestBase64, bool $withNamespace, string $referenceId = 'Request'): string
    {
        return '<SignedInfo' . ($withNamespace ? ' xmlns="' . self::DSIG_NS . '"' : '') . '>'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></CanonicalizationMethod>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"></SignatureMethod>'
            . '<Reference URI="#' . $referenceId . '"><Transforms>'
            . '<Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"></Transform>'
            . '<Transform Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"></Transform></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"></DigestMethod>'
            . '<DigestValue>' . $digestBase64 . '</DigestValue></Reference></SignedInfo>';
    }

    /** A dummy signature that satisfies the schema; used only for local schema validation of unsigned requests. */
    public static function withPlaceholderSignature(string $canonicalRequest): string
    {
        $element = '<Signature xmlns="' . self::DSIG_NS . '">' . self::signedInfo('AAAA', false)
            . '<SignatureValue>AAAA</SignatureValue><KeyInfo><X509Data><X509Certificate>AAAA</X509Certificate></X509Data></KeyInfo></Signature>';
        $close = strrpos($canonicalRequest, '</');
        return substr($canonicalRequest, 0, $close) . $element . substr($canonicalRequest, $close);
    }
}
