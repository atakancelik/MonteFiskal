<?php

declare(strict_types=1);

namespace MonteFiskal\Tests;

use MonteFiskal\Archive\OfflineNames;
use MonteFiskal\Calculation\InvoiceCalculator;
use MonteFiskal\DateTimeFormat;
use MonteFiskal\Errors\FiscalErrorClass;
use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\FiscalEnvironment;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\InvoiceLine;
use MonteFiskal\Model\SubsequentDelivery;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Numbering\InvoiceNumber;
use MonteFiskal\ResponseSignaturePolicy;
use MonteFiskal\Resources;
use MonteFiskal\Security\Ikof;
use MonteFiskal\Security\IkofParameters;
use MonteFiskal\Testing\VectorLoader;
use MonteFiskal\Testing\VectorXml;
use MonteFiskal\Validation\DepositValidator;
use MonteFiskal\Validation\Finding;
use MonteFiskal\Validation\InvoiceValidator;
use MonteFiskal\Validation\Severity;
use MonteFiskal\Validation\TcrValidator;
use MonteFiskal\Verification\VerificationUrl;
use MonteFiskal\VerificationUrlEncoding;
use MonteFiskal\Wire\ExchangeOutcome;
use MonteFiskal\Wire\InvoiceFacts;
use MonteFiskal\Wire\IssueValues;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\RequestHeader;
use MonteFiskal\Wire\ResponseParser;
use MonteFiskal\Wire\SchemaSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs every shared vector; IDs are logged so that CI can compare the .NET and PHP sets (vectors-parity). */
final class VectorTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function ids(string $kind): array
    {
        $out = [];
        foreach (VectorLoader::load($kind) as $v) {
            $out[$v['id']] = [$v['id']];
        }
        return $out;
    }

    public static function iic(): array
    {
        return self::ids('iic');
    }
    public static function url(): array
    {
        return self::ids('url');
    }
    public static function number(): array
    {
        return self::ids('number');
    }
    public static function time(): array
    {
        return self::ids('time');
    }
    public static function amounts(): array
    {
        return self::ids('amounts');
    }
    public static function xml(): array
    {
        return self::ids('xml');
    }
    public static function validate(): array
    {
        return self::ids('validate');
    }
    public static function fault(): array
    {
        return self::ids('fault');
    }
    public static function response(): array
    {
        return self::ids('response');
    }
    public static function offline(): array
    {
        return self::ids('offline');
    }

    /** @return array<string, mixed> */
    public static function vector(string $id): array
    {
        $log = getenv('MONTEFISKAL_VECTOR_LOG');
        if ($log !== false && $log !== '') {
            file_put_contents($log, $id . "\n", FILE_APPEND | LOCK_EX);
        }
        return VectorLoader::get($id);
    }

    private static function s(array $i, string $k): string
    {
        return (string) $i[$k];
    }

    private static function params(array $i): IkofParameters
    {
        return new IkofParameters(
            self::s($i, 'issuerTin'),
            self::s($i, 'issueDateTime'),
            self::s($i, 'ordinal'),
            self::s($i, 'businessUnit'),
            self::s($i, 'tcr'),
            self::s($i, 'software'),
            self::s($i, 'totalPrice')
        );
    }

    #[DataProvider('iic')]
    public function testIicVector(string $id): void
    {
        $v = self::vector($id);
        $p = self::params($v['input']);
        $e = $v['expected'];
        self::assertSame($e['concat'], Ikof::concatenateParameters($p));
        if (isset($e['publicKeyPem'])) {
            self::assertSame($e['signatureVerifies'], Ikof::verify(Ikof::concatenateParameters($p), $e['signatureHex'], $e['publicKeyPem']));
            if (isset($e['iic'])) {
                // BR-I3: IIC is the MD5 of the signature bytes.
                self::assertSame($e['iic'], Ikof::iicFromSignature((string) hex2bin($e['signatureHex'])));
            }
        }
        // BR-I7: signing is deterministic with any key.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $a = Ikof::computeParameters($p, $key);
        self::assertEquals($a, Ikof::computeParameters($p, $key));
        self::assertTrue(Ikof::verify(Ikof::concatenateParameters($p), $a->iicSignature, openssl_pkey_get_details($key)['key']));
    }

    #[DataProvider('url')]
    public function testUrlVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $env = $i['environment'] === 'production' ? FiscalEnvironment::Production : FiscalEnvironment::Test;
        $enc = $i['encoding'] === 'none' ? VerificationUrlEncoding::None : VerificationUrlEncoding::Percent;
        self::assertSame($v['expected']['url'], VerificationUrl::build(VerificationUrl::baseFor($env), $i['iic'], self::params($i), $enc));
    }

    #[DataProvider('number')]
    public function testNumberVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $issued = FiscalTime::parse($i['issuedAt']);
        $error = null;
        $num = null;
        try {
            $num = InvoiceNumber::format($i['businessUnit'], (int) $i['ordinal'], $issued, $i['tcr'], $i['timeZone']);
            if (!SchemaSet::isValid('InvNumSType', $num)) {
                $error = 'SCHEMA';
            }
        } catch (\OutOfRangeException) {
            $error = 'BR-N2';
        }
        if (array_key_exists('error', $v['expected'])) {
            self::assertSame($v['expected']['error'], $error);
            return;
        }
        self::assertNull($error);
        self::assertSame($v['expected']['invNum'], $num);
        if (isset($v['expected']['issueDateTime'])) {
            self::assertSame($v['expected']['issueDateTime'], FiscalTime::format($issued, $i['timeZone']));
        }
    }

    #[DataProvider('time')]
    public function testTimeVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $format = $i['policy'] === 'utc' ? DateTimeFormat::Utc : DateTimeFormat::LocalOffset;
        self::assertSame($v['expected']['formatted'], FiscalTime::format(FiscalTime::parse($i['instant']), $i['timeZone'], $format));
    }

    #[DataProvider('amounts')]
    public function testAmountsVector(string $id): void
    {
        $v = self::vector($id);
        $lines = array_map(static fn (array $l): InvoiceLine => InvoiceLine::fromArray($l), $v['input']['lines']);
        $r = InvoiceCalculator::default()->calculate($lines, (bool) $v['input']['issuerInVat'], (int) $v['input']['itemDecimals']);
        $e = $v['expected'];
        self::assertCount(count($e['items']), $r->lines);
        foreach ($e['items'] as $n => $item) {
            self::assertEquals(self::sorted($item), self::sorted($r->lines[$n]->attributes), "item {$n}");
        }
        self::assertEquals(self::sorted($e['totals']), self::sorted($r->totals->attributes()));
        self::assertCount(count($e['sameTaxes']), $r->sameTaxes);
        foreach ($e['sameTaxes'] as $n => $g) {
            self::assertEquals(self::sorted($g), self::sorted($r->sameTaxes[$n]));
        }
    }

    private static function sorted(array $a): array
    {
        ksort($a, SORT_STRING);
        return array_map('strval', $a);
    }

    #[DataProvider('xml')]
    public function testXmlVector(string $id): void
    {
        $v = self::vector($id);
        $xml = VectorXml::build($v, $facts);
        $e = $v['expected'];
        self::assertSame($e['unsignedXml'], $xml);
        self::assertSame($e['byteLength'], strlen($xml));
        self::assertSame($e['canonicalSha256'], hash('sha256', $xml));
        // The written form is already exclusive C14N: libxml's canonicalizer gives the same bytes.
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        self::assertSame($xml, $doc->documentElement->C14N(true, false));
        if ($facts !== null) {
            self::assertSame($e['invNum'], $facts->invNum);
            self::assertSame($e['issueDateTime'], $facts->issueDateTime);
            self::assertSame($e['iicConcat'], $facts->iicConcat);
            self::assertSame($e['typeOfInv'], $facts->typeOfInv->value);
        }
        // Every generated request is valid against the bundled official XSD.
        self::assertSame([], SchemaSet::validateUnsigned($xml));
    }

    #[DataProvider('validate')]
    public function testValidateVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        if ($i['message'] === 'catalogTypes') {
            $this->catalogTypes($i['samples']);
            return;
        }
        $result = match ($i['message']) {
            'invoice' => InvoiceValidator::validate(FiscalProfile::fromArray($i['profile']), InvoiceDraft::fromArray($i['draft']), FiscalTime::parse($i['now'])),
            'tcr' => TcrValidator::validate(TcrRegistration::fromArray($i['registration']), $i['today']),
            default => DepositValidator::validate(CashDeposit::fromArray($i['deposit'])),
        };
        $expected = array_map(static fn (array $f): string => $f['rule'] . '|' . $f['severity'], $v['expected']['findings']);
        $actual = array_values(array_unique(array_map(static fn (Finding $f): string => $f->rule . '|' . ($f->severity === Severity::Error ? 'error' : 'warning'), $result->findings)));
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual, implode("\n", array_map('strval', $result->findings)));
        self::assertSame($v['expected']['valid'], $result->isValid());
    }

    private function catalogTypes(array $samples): void
    {
        $catalog = Resources::json('schemas/fs-v1/2026-11/message-catalog.json');
        $patternTypes = array_keys(array_filter($catalog['simpleTypes'], static fn (array $t): bool => isset($t['patterns'])));
        foreach (array_keys($samples) as $covered) {
            self::assertContains($covered, $patternTypes);
        }
        foreach ($samples as $type => $s) {
            foreach ($s['valid'] as $ok) {
                self::assertTrue(SchemaSet::isValid($type, $ok), "{$type} should accept '{$ok}'");
            }
            foreach ($s['invalid'] as $bad) {
                self::assertFalse(SchemaSet::isValid($type, $bad), "{$type} should reject '{$bad}'");
            }
        }
    }

    #[DataProvider('fault')]
    public function testFaultVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $r = ResponseParser::parse($i['httpStatus'], $i['body']);
        $e = $v['expected'];
        self::assertSame($e['outcome'], $r->outcome->value);
        self::assertSame($e['retryable'], $r->retryable);
        if ($e['outcome'] !== 'fault') {
            return;
        }
        $err = $r->error;
        self::assertSame($e['code'], $err->code);
        self::assertSame($e['class'], $err->class->value);
        self::assertSame($e['blocking'], $err->blocking);
        self::assertSame($e['faultCode'], $err->faultCode);
        self::assertSame($e['faultString'], $err->faultString);
        self::assertSame($e['requestUuid'], $err->requestUuid);
        self::assertSame($e['responseUuid'], $err->responseUuid);
    }

    #[DataProvider('response')]
    public function testResponseVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $r = ResponseParser::parse($i['httpStatus'], $i['body'], $i['verifySignature'] ? ResponseSignaturePolicy::Verify : ResponseSignaturePolicy::Ignore);
        $e = $v['expected'];
        self::assertSame(ExchangeOutcome::Success, $r->outcome);
        self::assertSame($e['element'], $r->element);
        self::assertSame($e['result'], $r->result);
        self::assertSame($e['requestUuid'], $r->requestUuid);
        self::assertSame($e['responseUuid'], $r->responseUuid);
        self::assertSame($e['sendDateTime'], $r->sendDateTime);
        self::assertSame($e['signaturePresent'], $r->signature?->present ?? false);
    }

    #[DataProvider('offline')]
    public function testOfflineVector(string $id): void
    {
        $v = self::vector($id);
        $i = $v['input'];
        $files = array_map(static fn (array $x): string => OfflineNames::requestFile($x['issueDateTime'], $x['tcr'], $x['iic']), $i['invoices']);
        self::assertSame($v['expected']['files'], $files);
        $at = OfflineNames::parseWire($i['exportedAt']);
        self::assertSame($v['expected']['zip'], OfflineNames::requestZip($at, $i['tcr']));
        self::assertSame($v['expected']['responseZip'], OfflineNames::responseZip($at, $i['tcr']));
        self::assertSame(OfflineNames::MAX_ZIP_BYTES, $v['expected']['maxZipBytes']);
    }
}
