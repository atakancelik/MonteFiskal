<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

use MonteFiskal\MonteFiskalOptions;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Transport over any PSR-18 client (install psr/http-client and a client of your choice). */
final class Psr18Transport implements FiscalTransport
{
    private readonly MonteFiskalOptions $options;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $endpoint,
        ?MonteFiskalOptions $options = null,
    ) {
        $this->options = $options ?? new MonteFiskalOptions();
    }

    public function send(FiscalOperation $operation, string $envelope): TransportResponse
    {
        $request = $this->requests->createRequest('POST', $this->endpoint)
            ->withHeader('Content-Type', 'text/xml; charset=utf-8')
            ->withBody($this->streams->createStream($envelope));
        if ($this->options->sendSoapAction) {
            $request = $request->withHeader('SOAPAction', '"' . FiscalEndpoints::soapAction($operation) . '"');
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FiscalTransportException('The fiscal service could not be reached: ' . $e->getMessage(), 0, $e);
        }
        return new TransportResponse($response->getStatusCode(), (string) $response->getBody());
    }
}
