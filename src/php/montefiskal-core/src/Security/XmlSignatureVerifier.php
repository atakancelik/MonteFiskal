<?php

declare(strict_types=1);

namespace MonteFiskal\Security;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Verifies enveloped signatures of requests and CIS responses (BR-S6) for the fixed profile of BR-S1 to BR-S4:
 * one reference, enveloped-signature and exclusive C14N, SHA-256 digest, RSA-SHA256.
 */
final class XmlSignatureVerifier
{
    private const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public static function verify(string $xml, ?string $expectedId = null): SignatureCheck
    {
        $doc = self::load($xml);
        if ($doc === null) {
            return new SignatureCheck(false, false, null, 'not well-formed XML');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', XmlSigner::DSIG_NS);
        $sigs = $xp->query('//ds:Signature');
        if ($sigs === false || $sigs->length === 0) {
            return new SignatureCheck(false, false, null, 'no signature');
        }
        if ($sigs->length > 1) {
            return new SignatureCheck(true, false, null, 'more than one signature');
        }
        /** @var DOMElement $sig */
        $sig = $sigs->item(0);
        $signedInfo = $xp->query('ds:SignedInfo', $sig)?->item(0);
        $refs = $xp->query('ds:SignedInfo/ds:Reference', $sig);
        if (!$signedInfo instanceof DOMElement || $refs === false || $refs->length !== 1) {
            return new SignatureCheck(true, false, null, 'exactly one reference expected');
        }
        /** @var DOMElement $ref */
        $ref = $refs->item(0);
        $uri = $ref->getAttribute('URI');
        if ($expectedId !== null && $uri !== '#' . $expectedId) {
            return new SignatureCheck(true, false, null, "reference {$uri} is not #{$expectedId}");
        }
        $certB64 = trim((string) $xp->query('ds:KeyInfo/ds:X509Data/ds:X509Certificate', $sig)?->item(0)?->textContent);
        $der = $certB64 === '' ? false : base64_decode(preg_replace('/\s+/', '', $certB64) ?? '', true);
        if ($der === false) {
            return new SignatureCheck(true, false, null, 'no X509Certificate in KeyInfo');
        }
        $c14n = (string) $xp->query('ds:SignedInfo/ds:CanonicalizationMethod/@Algorithm', $sig)?->item(0)?->nodeValue;
        $method = (string) $xp->query('ds:SignedInfo/ds:SignatureMethod/@Algorithm', $sig)?->item(0)?->nodeValue;
        $digestMethod = (string) $xp->query('ds:DigestMethod/@Algorithm', $ref)?->item(0)?->nodeValue;
        $transforms = [];
        foreach ($xp->query('ds:Transforms/ds:Transform/@Algorithm', $ref) ?: [] as $t) {
            $transforms[] = $t->nodeValue;
        }
        if ($c14n !== self::EXC_C14N || $method !== self::RSA_SHA256 || $digestMethod !== self::SHA256 || $transforms !== [self::ENVELOPED, self::EXC_C14N]) {
            return new SignatureCheck(true, false, $der, 'algorithms differ from the fixed profile (BR-S1 to BR-S4)');
        }

        // Reference: the element with that Id, without the signature, in exclusive C14N.
        $id = substr($uri, 1);
        $copy = self::load($xml);
        $cxp = new DOMXPath($copy);
        $cxp->registerNamespace('ds', XmlSigner::DSIG_NS);
        $targets = $cxp->query("//*[@Id='" . str_replace("'", '', $id) . "']");
        if ($targets === false || $targets->length !== 1) {
            return new SignatureCheck(true, false, $der, 'referenced element not found exactly once');
        }
        $csig = $cxp->query('//ds:Signature')->item(0);
        $csig->parentNode->removeChild($csig);
        /** @var DOMElement $target */
        $target = $targets->item(0);
        $digest = base64_encode(hash('sha256', (string) $target->C14N(true, false), true));
        $expected = trim((string) $xp->query('ds:DigestValue', $ref)?->item(0)?->textContent);
        if (!hash_equals($expected, $digest)) {
            return new SignatureCheck(true, false, $der, 'signature value or digest does not match');
        }
        $value = base64_decode(preg_replace('/\s+/', '', (string) $xp->query('ds:SignatureValue', $sig)?->item(0)?->textContent) ?? '', true);
        $ok = $value !== false && openssl_verify((string) $signedInfo->C14N(true, false), $value, Certificate::derToPem($der), OPENSSL_ALGO_SHA256) === 1;
        return new SignatureCheck(true, $ok, $der, $ok ? null : 'signature value or digest does not match');
    }

    /** Parses without network access and without a DTD. */
    public static function load(string $xml): ?DOMDocument
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            return null;
        }
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = true;
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }
}
