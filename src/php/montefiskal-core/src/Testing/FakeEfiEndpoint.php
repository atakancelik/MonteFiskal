<?php

declare(strict_types=1);

namespace MonteFiskal\Testing;

use DOMElement;
use DOMXPath;
use MonteFiskal\Calculation\Dec;
use MonteFiskal\Clock;
use MonteFiskal\RandomUuidGenerator;
use MonteFiskal\Security\Certificate;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Security\XmlSigner;
use MonteFiskal\SystemClock;
use MonteFiskal\Transport\FiscalEndpoints;
use MonteFiskal\Transport\FiscalOperation;
use MonteFiskal\Transport\FiscalTransport;
use MonteFiskal\Transport\TransportResponse;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\SchemaSet;
use MonteFiskal\Wire\XNode;

/**
 * In-process stand-in for the EFI service (fs-v1), the same behaviour as the .NET FakeEfiEndpoint. Validates
 * every request against the bundled XSD and its signature, enforces the INITIAL-before-cash rule (code 58),
 * answers with signed responses, and can be told to fail. It never opens a network connection.
 */
final class FakeEfiEndpoint implements FiscalTransport
{
    public readonly Certificate $serviceCertificate;
    /** Enforce BR-D5 (code 58 for a cash invoice without the day's INITIAL). */
    public bool $requireInitialDeposit = true;
    /** Sign responses (BR-S6). */
    public bool $signResponses = true;

    /** @var list<callable(string): ?array{0: int, 1: string}> */
    private array $script = [];
    /** @var array<string, string> */
    private array $initialDeposits = [];
    /** @var array<string, string> */
    private array $tcrCodes = [];
    /** @var array<string, string> */
    private array $registeredIics = [];
    /** @var list<array{soapAction: string, requestElement: string, body: string, statusCode: int, response: string}> */
    private array $log = [];
    private readonly Clock $clock;
    private readonly RandomUuidGenerator $uuids;

    public function __construct(?Clock $clock = null, ?Certificate $serviceCertificate = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->serviceCertificate = $serviceCertificate ?? TestCertificateFactory::create('00000000', 'MonteFiskal Fake EFI Service');
        $this->uuids = new RandomUuidGenerator();
    }

    /** @return list<array{soapAction: string, requestElement: string, body: string, statusCode: int, response: string}> */
    public function exchanges(): array
    {
        return $this->log;
    }

    /** The next request fails with this fault code. */
    public function failNextWith(int $code, string $faultString = 'Simulated fault.', bool $server = false): self
    {
        $this->script[] = fn (string $req): array => [500, $this->fault($code, $faultString, $server, self::requestUuid($req))];
        return $this;
    }

    /** The next request gets an HTTP error page (transport failure). */
    public function failNextWithHttp(int $status = 503, string $body = '<html><body>Service Unavailable</body></html>'): self
    {
        $this->script[] = static fn (string $req): array => [$status, $body];
        return $this;
    }

    /** @param callable(string): ?array{0: int, 1: string} $responder The next request is answered by it; null falls through. */
    public function next(callable $responder): self
    {
        $this->script[] = $responder;
        return $this;
    }

    public function send(FiscalOperation $operation, string $envelope): TransportResponse
    {
        [$status, $body] = $this->handle(FiscalEndpoints::soapAction($operation), $envelope);
        return new TransportResponse($status, $body);
    }

    /** @return array{0: int, 1: string} */
    public function handle(string $action, string $envelope): array
    {
        $scripted = array_shift($this->script);
        if ($scripted !== null && ($s = $scripted($envelope)) !== null) {
            return $this->record($action, '', $envelope, $s[0], $s[1]);
        }
        if (strlen($envelope) > 150 * 1024) {
            return $this->record($action, '', $envelope, 500, $this->fault(1, 'Message too large.', false, null));
        }
        $doc = XmlSignatureVerifier::load($envelope);
        if ($doc === null) {
            return $this->record($action, '', $envelope, 500, $this->fault(10, 'Exception while reading the XML.', false, null));
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $req = $xp->query('/soap:Envelope/soap:Body/*')?->item(0);
        if (!$req instanceof DOMElement) {
            return $this->record($action, '', $envelope, 500, $this->fault(22, 'Request element missing.', false, null));
        }
        $element = $req->localName;
        $reqUuid = $xp->query("*[local-name()='Header']/@UUID", $req)?->item(0)?->nodeValue;
        $schema = SchemaSet::validate((string) $doc->saveXML($req));
        if ($schema !== []) {
            return $this->record($action, $element, $envelope, 500, $this->fault(11, 'Validation of the received XML message failed: ' . $schema[0], false, $reqUuid));
        }
        $sig = XmlSignatureVerifier::verify($envelope, 'Request');
        if (!$sig->present) {
            return $this->record($action, $element, $envelope, 500, $this->fault(21, 'Signature element missing.', false, $reqUuid));
        }
        if (!$sig->valid) {
            return $this->record($action, $element, $envelope, 500, $this->fault(29, 'Validation failed: ' . $sig->problem, false, $reqUuid));
        }
        /** @var DOMElement $a */
        $a = $xp->query('*[2]', $req)->item(0);
        [$status, $response] = match ($element) {
            'RegisterInvoiceRequest' => $this->invoice($a, (string) $reqUuid),
            'RegisterTCRRequest' => $this->tcr($a, (string) $reqUuid),
            'RegisterCashDepositRequest' => $this->deposit($a, (string) $reqUuid),
            default => [500, $this->fault(22, 'Unknown request element.', false, $reqUuid)],
        };
        return $this->record($action, $element, $envelope, $status, $response);
    }

    /** @return array{0: int, 1: string} */
    private function invoice(DOMElement $inv, string $reqUuid): array
    {
        $tcr = $inv->getAttribute('TCRCode');
        $day = substr($inv->getAttribute('IssueDateTime'), 0, 10);
        if ($this->requireInitialDeposit && $inv->getAttribute('TypeOfInv') === 'CASH' && !isset($this->initialDeposits[$tcr . '/' . $day])) {
            return [500, $this->fault(58, 'Cash deposit INITIAL is not registered for the current day.', false, $reqUuid)];
        }
        $iic = strtoupper($inv->getAttribute('IIC'));
        $fic = $this->registeredIics[$iic] ??= $this->uuids->newUuid();
        return [200, $this->response('RegisterInvoiceResponse', $reqUuid, 'FIC', $fic)];
    }

    /** @return array{0: int, 1: string} */
    private function tcr(DOMElement $tcr, string $reqUuid): array
    {
        $key = $tcr->getAttribute('IssuerTIN') . '/' . $tcr->getAttribute('BusinUnitCode') . '/' . $tcr->getAttribute('TCRIntID');
        // BR-R3: the same TCRIntID and business unit return the same TCR code.
        if (!isset($this->tcrCodes[$key])) {
            $n = 100 + count($this->tcrCodes);
            $this->tcrCodes[$key] = "tc{$n}tc{$n}";
        }
        return [200, $this->response('RegisterTCRResponse', $reqUuid, 'TCRCode', $this->tcrCodes[$key])];
    }

    /** @return array{0: int, 1: string} */
    private function deposit(DOMElement $dep, string $reqUuid): array
    {
        $op = $dep->getAttribute('Operation');
        $amount = $dep->getAttribute('CashAmt');
        if ($op === 'WITHDRAW' && Dec::isZero($amount)) {
            return [500, $this->fault(51, 'Cash amount cannot be zero for WITHDRAW.', false, $reqUuid)];
        }
        if ($op === 'INITIAL' && Dec::cmp($amount, '0') < 0) {
            return [500, $this->fault(50, 'Cash amount for INITIAL cannot be negative.', false, $reqUuid)];
        }
        if ($op === 'INITIAL') {
            $this->initialDeposits[$dep->getAttribute('TCRCode') . '/' . substr($dep->getAttribute('ChangeDateTime'), 0, 10)] = $amount;
        }
        return [200, $this->response('RegisterCashDepositResponse', $reqUuid, 'FCDC', $this->uuids->newUuid())];
    }

    private function response(string $element, string $reqUuid, string $resultName, string $result): string
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Podgorica'))->format('Y-m-d\TH:i:sP');
        $xml = (string) (new XNode($element, RequestBuilder::NS))->attr('Id', 'Response')->attr('Version', '1')
            ->add((new XNode('Header'))->attr('RequestUUID', $reqUuid)->attr('UUID', $this->uuids->newUuid())->attr('SendDateTime', $now))
            ->add((new XNode($resultName))->withText($result));
        if ($this->signResponses) {
            $xml = XmlSigner::sign($xml, $this->serviceCertificate, 'Response');
        }
        return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>' . $xml . '</env:Body></env:Envelope>';
    }

    private function fault(int $code, string $faultString, bool $server, ?string $reqUuid): string
    {
        return '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body><env:Fault><faultcode>env:' . ($server ? 'SERVER' : 'CLIENT')
            . '</faultcode><faultstring>' . XNode::escapeText($faultString) . '</faultstring><detail><code>' . $code . '</code>'
            . ($reqUuid === null ? '' : '<requestUUID>' . $reqUuid . '</requestUUID>') . '<responseUUID>' . $this->uuids->newUuid() . '</responseUUID></detail></env:Fault></env:Body></env:Envelope>';
    }

    private static function requestUuid(string $envelope): ?string
    {
        $i = strpos($envelope, ' UUID="');
        return $i === false ? null : substr($envelope, $i + 7, 36);
    }

    /** @return array{0: int, 1: string} */
    private function record(string $action, string $element, string $body, int $status, string $response): array
    {
        $this->log[] = ['soapAction' => $action, 'requestElement' => $element, 'body' => $body, 'statusCode' => $status, 'response' => $response];
        return [$status, $response];
    }
}
