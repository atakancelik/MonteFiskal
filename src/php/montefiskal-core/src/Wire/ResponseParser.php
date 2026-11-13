<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

use DOMElement;
use DOMXPath;
use MonteFiskal\Errors\ErrorCatalog;
use MonteFiskal\ResponseSignaturePolicy;
use MonteFiskal\Security\SignatureCheck;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Security\XmlSigner;

/** Reads SOAP responses and faults (BR-J1, BR-S6, error-codes.md). */
final class ResponseParser
{
    private const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const RESULT = ['RegisterInvoiceResponse' => 'FIC', 'RegisterTCRResponse' => 'TCRCode', 'RegisterCashDepositResponse' => 'FCDC'];

    public static function parse(int $httpStatus, ?string $body, ResponseSignaturePolicy $policy = ResponseSignaturePolicy::Verify): ParsedResponse
    {
        if ($body === null || trim($body) === '' || ($httpStatus !== 200 && $httpStatus !== 500)) {
            return new ParsedResponse(ExchangeOutcome::Transport, true, problem: "HTTP {$httpStatus} without a SOAP answer");
        }
        $trimmed = ltrim($body);
        if (!str_starts_with($trimmed, '<') || stripos($trimmed, '<html') === 0 || stripos($trimmed, '<!DOCTYPE html') === 0) {
            return new ParsedResponse(ExchangeOutcome::Transport, true, problem: 'the answer is not XML');
        }
        $doc = XmlSignatureVerifier::load($body);
        if ($doc === null) {
            return new ParsedResponse(ExchangeOutcome::MalformedResponse, true, problem: 'the answer is not well-formed XML');
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('soap', self::SOAP_NS);
        $xp->registerNamespace('fs', RequestBuilder::NS);
        $bodyEl = $xp->query('/soap:Envelope/soap:Body')?->item(0);
        if (!$bodyEl instanceof DOMElement) {
            return new ParsedResponse(ExchangeOutcome::MalformedResponse, true, problem: 'no SOAP body');
        }
        $fault = $xp->query('soap:Fault', $bodyEl)?->item(0);
        if ($fault instanceof DOMElement) {
            $text = static fn (string $path): ?string => $xp->query($path, $fault)?->item(0)?->textContent;
            $faultCode = trim($text('faultcode') ?? '');
            $local = str_contains($faultCode, ':') ? substr($faultCode, strpos($faultCode, ':') + 1) : $faultCode;
            $faultString = $text('faultstring') ?? '';
            $codeText = trim($text('detail/code') ?? '');
            $code = preg_match('/^[+-]?\d+$/', $codeText) ? (int) $codeText : null;
            $req = self::uuid($text('detail/requestUUID'));
            $resp = self::uuid($text('detail/responseUUID'));
            $error = ErrorCatalog::classify($code, $local, $faultString, $req, $resp);
            return new ParsedResponse(ExchangeOutcome::Fault, $error->retryable, requestUuid: $req, responseUuid: $resp, error: $error);
        }
        $element = null;
        foreach ($bodyEl->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $element = $c;
                break;
            }
        }
        if ($element === null || $element->namespaceURI !== RequestBuilder::NS || !isset(self::RESULT[$element->localName])) {
            return new ParsedResponse(ExchangeOutcome::MalformedResponse, true, problem: 'unexpected body element');
        }
        $resultName = self::RESULT[$element->localName];
        $header = $xp->query('fs:Header', $element)?->item(0);
        $result = $xp->query('fs:' . $resultName, $element)?->item(0)?->textContent;
        if ($result === null || $result === '') {
            return new ParsedResponse(ExchangeOutcome::MalformedResponse, true, problem: $resultName . ' missing');
        }
        $hasSignature = $element->getElementsByTagNameNS(XmlSigner::DSIG_NS, 'Signature')->length > 0;
        $sig = null;
        if ($policy !== ResponseSignaturePolicy::Ignore) {
            $sig = $hasSignature ? XmlSignatureVerifier::verify($body, 'Response') : new SignatureCheck(false, false, null, 'no signature');
            if ($policy === ResponseSignaturePolicy::Require && !$sig->valid) {
                return new ParsedResponse(ExchangeOutcome::MalformedResponse, true, $element->localName, $result, signature: $sig, problem: 'response signature: ' . $sig->problem);
            }
        }
        $attr = static fn (string $n): ?string => $header instanceof DOMElement && $header->hasAttribute($n) ? $header->getAttribute($n) : null;
        return new ParsedResponse(
            ExchangeOutcome::Success,
            false,
            $element->localName,
            $result,
            self::uuid($attr('RequestUUID')),
            self::uuid($attr('UUID')),
            $attr('SendDateTime'),
            $sig ?? new SignatureCheck($hasSignature, false, null, 'not checked')
        );
    }

    private static function uuid(?string $s): ?string
    {
        $s = trim((string) $s);
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $s) ? strtolower($s) : null;
    }
}
