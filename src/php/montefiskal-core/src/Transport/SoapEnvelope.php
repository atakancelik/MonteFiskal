<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

/** SOAP 1.1 envelope with an empty header; the signed request is the only child of the body (BR-T3). */
final class SoapEnvelope
{
    public static function wrap(string $signedRequest): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Header></soap:Header><soap:Body>'
            . $signedRequest . '</soap:Body></soap:Envelope>';
    }
}
