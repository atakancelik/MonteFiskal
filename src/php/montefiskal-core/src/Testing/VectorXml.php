<?php

declare(strict_types=1);

namespace MonteFiskal\Testing;

use MonteFiskal\FiscalProfile;
use MonteFiskal\Model\CashDeposit;
use MonteFiskal\Model\InvoiceDraft;
use MonteFiskal\Model\SubsequentDelivery;
use MonteFiskal\Model\TcrRegistration;
use MonteFiskal\Numbering\FiscalTime;
use MonteFiskal\Wire\InvoiceFacts;
use MonteFiskal\Wire\IssueValues;
use MonteFiskal\Wire\RequestBuilder;
use MonteFiskal\Wire\RequestHeader;

/** Builds the unsigned request of an xml vector (shared by the vector tests and the cross-signature tool). */
final class VectorXml
{
    /** @param array<string, mixed> $v */
    public static function build(array $v, ?InvoiceFacts &$facts = null): string
    {
        $i = $v['input'];
        $issue = $i['issue'];
        $header = new RequestHeader(
            $issue['uuid'],
            FiscalTime::parse($issue['sendDateTime']),
            isset($issue['subsequentDelivery']) ? SubsequentDelivery::from($issue['subsequentDelivery']) : null
        );
        $facts = null;
        switch ($i['message']) {
            case 'invoice':
                $profile = FiscalProfile::fromArray($i['profile']);
                $draft = InvoiceDraft::fromArray($i['draft']);
                $ordinal = (int) $issue['ordinal'];
                $facts = RequestBuilder::facts($profile, $draft, $ordinal);
                $inv = RequestBuilder::invoice($profile, $draft, $facts, new IssueValues($ordinal, $issue['iic'], $issue['iicSignature']));
                return RequestBuilder::invoiceRequest((string) $inv, $header, $profile->timeZone);
            case 'tcr':
                return (string) RequestBuilder::root('RegisterTCRRequest', RequestBuilder::header($header, 'Europe/Podgorica'), RequestBuilder::tcr(TcrRegistration::fromArray($i['registration'])));
            default:
                return (string) RequestBuilder::root(
                    'RegisterCashDepositRequest',
                    RequestBuilder::header($header, 'Europe/Podgorica'),
                    RequestBuilder::deposit(CashDeposit::fromArray($i['deposit']), 'Europe/Podgorica')
                );
        }
    }
}
