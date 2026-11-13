<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

use MonteFiskal\MonteFiskalOptions;

/** HTTP POST of SOAP 1.1 over one-way TLS (BR-T2) with ext-curl; no extra package needed. */
final class CurlTransport implements FiscalTransport
{
    private readonly MonteFiskalOptions $options;

    public function __construct(private readonly string $endpoint, ?MonteFiskalOptions $options = null)
    {
        $this->options = $options ?? new MonteFiskalOptions();
    }

    public function send(FiscalOperation $operation, string $envelope): TransportResponse
    {
        $headers = ['Content-Type: text/xml; charset=utf-8'];
        if ($this->options->sendSoapAction) {
            // Q-22: SOAP 1.1 action from the WSDL, quoted.
            $headers[] = 'SOAPAction: "' . FiscalEndpoints::soapAction($operation) . '"';
        }
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->options->sendTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->options->sendTimeoutSeconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            throw new FiscalTransportException('The fiscal service could not be reached: ' . $error);
        }
        return new TransportResponse((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body);
    }
}
