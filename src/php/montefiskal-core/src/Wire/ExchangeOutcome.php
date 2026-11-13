<?php

declare(strict_types=1);

namespace MonteFiskal\Wire;

/** Outcome of one HTTP exchange with CIS. */
enum ExchangeOutcome: string
{
    /** A response element with its result (FIC, TCRCode or FCDC). */
    case Success = 'success';
    /** A SOAP fault with a fiscal error. */
    case Fault = 'fault';
    /** The service was not reached or answered with something that is not SOAP (proxy page, empty body). */
    case Transport = 'transport';
    /** The answer looked like SOAP but could not be read. Retryable: the request may or may not have been registered. */
    case MalformedResponse = 'malformedResponse';
}
