<?php

declare(strict_types=1);

// Cross-signature check, PHP side (architecture section 7). Same commands as tools/cross-signature/dotnet:
//   php cross-signature.php sign <cert.pem> <key.pem> <outDir>
//   php cross-signature.php verify <dir>

require dirname(__DIR__, 3) . '/tests/php/bootstrap.php';

use MonteFiskal\Security\Certificate;
use MonteFiskal\Security\Ikof;
use MonteFiskal\Security\IkofParameters;
use MonteFiskal\Security\XmlSignatureVerifier;
use MonteFiskal\Security\XmlSigner;
use MonteFiskal\Testing\VectorLoader;
use MonteFiskal\Testing\VectorXml;
use MonteFiskal\Wire\SchemaSet;

$cmd = $argv[1] ?? '';
if ($cmd === 'sign' && $argc >= 5) {
    $cert = Certificate::fromPem((string) file_get_contents($argv[2]), (string) file_get_contents($argv[3]));
    @mkdir($argv[4], 0700, true);
    $n = 0;
    foreach (VectorLoader::load('xml') as $v) {
        file_put_contents($argv[4] . '/' . $v['id'] . '.xml', XmlSigner::sign(VectorXml::build($v), $cert));
        $n++;
    }
    foreach (VectorLoader::load('iic') as $v) {
        $i = $v['input'];
        $p = new IkofParameters((string) $i['issuerTin'], (string) $i['issueDateTime'], (string) $i['ordinal'], (string) $i['businessUnit'],
            (string) $i['tcr'], (string) $i['software'], (string) $i['totalPrice']);
        $c = Ikof::computeParameters($p, $cert->privateKey());
        file_put_contents($argv[4] . '/' . $v['id'] . '.ikof', $c->concatenation . "\n" . $c->iicSignature . "\n" . $c->iic . "\n");
        $n++;
    }
    echo "PHP signed {$n} items into {$argv[4]}\n";
    exit(0);
}
if ($cmd === 'verify' && $argc >= 3) {
    $dir = $argv[2];
    $failures = 0;
    $count = 0;
    $files = glob($dir . '/*.xml') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $f) {
        $xml = (string) file_get_contents($f);
        $check = XmlSignatureVerifier::verify($xml, 'Request');
        $schema = SchemaSet::validate($xml);
        $count++;
        if (!$check->valid || $schema !== []) {
            $failures++;
            fwrite(STDERR, 'FAIL ' . basename($f) . ': ' . $check->problem . ' ' . implode('; ', $schema) . "\n");
        }
    }
    $certPem = (string) file_get_contents($dir . '/cert.pem');
    foreach (glob($dir . '/*.ikof') ?: [] as $f) {
        [$concat, $sig, $iic] = explode("\n", (string) file_get_contents($f));
        $count++;
        if (!Ikof::verify($concat, $sig, openssl_pkey_get_public($certPem)) || Ikof::iicFromSignature((string) hex2bin($sig)) !== $iic) {
            $failures++;
            fwrite(STDERR, 'FAIL ' . basename($f) . ": IKOF does not verify\n");
        }
    }
    echo "PHP verified {$count} items from {$dir}: {$failures} failures\n";
    exit($failures === 0 && $count > 0 ? 0 : 1);
}
fwrite(STDERR, "usage: sign <cert.pem> <key.pem> <outDir> | verify <dir>\n");
exit(2);
