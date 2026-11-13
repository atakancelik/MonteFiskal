<?php

declare(strict_types=1);

namespace MonteFiskal\Transport;

/** Sends an envelope and returns the raw answer. Network failures throw FiscalTransportException. */
interface FiscalTransport
{
    public function send(FiscalOperation $operation, string $envelope): TransportResponse;
}
