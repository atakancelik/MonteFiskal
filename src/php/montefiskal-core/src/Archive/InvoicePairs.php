<?php

declare(strict_types=1);

namespace MonteFiskal\Archive;

use DateTimeImmutable;
use DOMElement;
use MonteFiskal\Model\InvoiceState;
use MonteFiskal\Outbox\OutboxStore;
use MonteFiskal\Outbox\StoredRequest;
use MonteFiskal\ResponseSignaturePolicy;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\Wire\ExchangeOutcome;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\ResponseParser;

/**
 * Request and response pairs of fiscalized invoices (BR-X7): for each invoice, the signed request and the response of
 * the exchange that received the JIKR, written as <yyyyMMddHHmmss>_<TCR>_<IIC>_request.xml and _response.xml
 * (BR-O11 names). The content is the archived text unchanged (BR-X2). Nothing is sent and nothing stored changes.
 */
final class InvoicePairs
{
    public const NOT_AN_INVOICE = 'not-an-invoice';
    public const NOT_FISCALIZED = 'not-fiscalized';
    public const NO_RESPONSE = 'no-response';
    public const NO_MATCHING_REQUEST = 'no-matching-request';

    /**
     * Picks the pair of one invoice, or returns the reason it has none. Archived exchanges are searched newest first,
     * then the outbox's last request and response. The response must carry the invoice's FIC; the request is the one
     * whose header UUID equals the response's RequestUUID (BR-X7).
     *
     * @param list<array{kind: string, content: string}> $archived As returned by ArchiveStore::load().
     */
    public static function select(StoredRequest $stored, array $archived): InvoicePair|string
    {
        if ($stored->operation !== FiscalOperation::RegisterInvoice) {
            return self::NOT_AN_INVOICE;
        }
        if ($stored->state !== InvoiceState::Fiscalized || $stored->result === null || $stored->result === '') {
            return self::NOT_FISCALIZED;
        }
        $responses = self::candidates($archived, 'response', $stored->lastResponse);
        $requests = self::candidates($archived, 'request', $stored->lastSignedRequest);
        $foundResponse = false;
        foreach ($responses as [$responseSource, $response]) {
            $requestUuid = self::answeredRequestUuid($response, $stored->result);
            if ($requestUuid === null) {
                continue;
            }
            $foundResponse = true;
            foreach ($requests as [$requestSource, $request]) {
                if (self::requestUuid($request) === $requestUuid) {
                    return new InvoicePair(
                        OfflineNames::requestFile((string) $stored->issueDateTime, $stored->tcr, (string) $stored->iic),
                        $request,
                        $requestSource,
                        OfflineNames::responseFile((string) $stored->issueDateTime, $stored->tcr, (string) $stored->iic),
                        $response,
                        $responseSource,
                    );
                }
            }
        }
        return $foundResponse ? self::NO_MATCHING_REQUEST : self::NO_RESPONSE;
    }

    /**
     * Writes the pair of every invoice issued in [$from, $to] to $directory (created with mode 0700). Existing files
     * of the same name are replaced; their content is the same archived text.
     */
    public static function export(OutboxStore $store, ArchiveStore $archive, DateTimeImmutable $from, DateTimeImmutable $to, string $directory): PairExportResult
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create ' . $directory);
        }
        $written = [];
        $skipped = [];
        foreach ($store->list($from, $to) as $stored) {
            if ($stored->operation !== FiscalOperation::RegisterInvoice) {
                continue;
            }
            $pair = self::select($stored, $archive->load($stored->id));
            if (is_string($pair)) {
                $skipped[] = ['id' => $stored->id, 'iic' => $stored->iic, 'reason' => $pair];
                continue;
            }
            foreach ([$pair->requestFile => $pair->request, $pair->responseFile => $pair->response] as $name => $content) {
                $path = rtrim($directory, '/') . '/' . $name;
                if (file_put_contents($path, $content, LOCK_EX) === false) {
                    throw new \RuntimeException('Cannot write ' . $path);
                }
                $written[] = $path;
            }
        }
        return new PairExportResult($written, $skipped);
    }

    /**
     * @param list<array{kind: string, content: string}> $archived
     * @return list<array{0: string, 1: string}> [source, content], newest first
     */
    private static function candidates(array $archived, string $kind, ?string $last): array
    {
        $out = [];
        for ($i = count($archived) - 1; $i >= 0; $i--) {
            if ($archived[$i]['kind'] === $kind) {
                $out[] = ['archive:' . $i, $archived[$i]['content']];
            }
        }
        if ($last !== null && $last !== '') {
            $out[] = ['last', $last];
        }
        return $out;
    }

    /** RequestUUID of a RegisterInvoiceResponse that carries $fic, or null. Accepts the SOAP envelope or the bare element. */
    private static function answeredRequestUuid(string $response, string $fic): ?string
    {
        $parsed = ResponseParser::parse(200, self::envelope($response), ResponseSignaturePolicy::Ignore);
        if ($parsed->outcome !== ExchangeOutcome::Success || $parsed->element !== 'RegisterInvoiceResponse' || $parsed->requestUuid === null) {
            return null;
        }
        return strcasecmp((string) $parsed->result, $fic) === 0 ? $parsed->requestUuid : null;
    }

    /** UUID of the header of a RegisterInvoiceRequest, lower case, or null. */
    private static function requestUuid(string $request): ?string
    {
        $doc = XmlSignatureVerifier::load($request);
        $root = $doc?->documentElement;
        if (!$root instanceof DOMElement) {
            return null;
        }
        if ($root->localName === 'Envelope') {
            $root = self::firstElement(self::firstElement($root, 'Body'), 'RegisterInvoiceRequest');
        }
        if (!$root instanceof DOMElement || $root->localName !== 'RegisterInvoiceRequest' || $root->namespaceURI !== RequestBuilder::NS) {
            return null;
        }
        $header = self::firstElement($root, 'Header');
        $uuid = $header?->getAttribute('UUID') ?? '';
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) ? strtolower($uuid) : null;
    }

    private static function firstElement(?DOMElement $parent, string $localName): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $localName) {
                return $c;
            }
        }
        return null;
    }

    /** Wraps a bare response element in a SOAP envelope for the parser; the archived text itself is not changed. */
    private static function envelope(string $xml): string
    {
        $xml = ltrim($xml);
        if (str_starts_with($xml, '<?xml')) {
            $xml = ltrim(substr($xml, (int) strpos($xml, '?>') + 2));
        }
        return preg_match('/^<([A-Za-z_][\w.-]*:)?Envelope[\s>]/', $xml) ? $xml
            : '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' . $xml . '</s:Body></s:Envelope>';
    }
}
