<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;
use Techork\PaymentService\ConnexPay\TerminateCard;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

function cpTerminate(?ConnexPayHttpClientInterface $client = null): TerminateCard
{
    return new TerminateCard(
        new TerminateCardCommand(GatewayId::generate(), 'card-guid-xyz'),
        $client ?? cpHttpClient(),
    );
}

it('sends an empty body, because the card is named in the path', function () {
    expect(cpTerminate()->payload())->toBe([]);
});

/*
 * One test is gone and cannot come back: "throws when transactionReference is missing". The card
 * guid is a required constructor argument of {@see TerminateCardCommand}, so an operation with
 * no card to terminate cannot be built.
 */

it('sends POST to /api/v1/TerminateCard/{cardGuid} and reports the card guid', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/TerminateCard/card-guid-xyz', [])
        ->andReturn(['terminateDate' => '2026-04-21']);

    $result = cpTerminate($client)->terminate();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('card-guid-xyz');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network unreachable'));

    $result = cpTerminate($client)->terminate();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Network unreachable');
});
