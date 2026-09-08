<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Revolut\RevolutHttpClientInterface;
use Techork\PaymentService\Revolut\TerminateCard;

function revolutTerminateCommand(string $cardGuid = 'card-1'): TerminateCardCommand
{
    return new TerminateCardCommand(GatewayId::generate(), $cardGuid);
}

/*
 * `it('requires the card reference')` and `it('sends no body')` lived here. The card is a
 * constructor argument, and there is no body to send or to assert on — a DELETE carries none, and
 * the request object that used to have to answer `getData()` for it is gone.
 */

it('deletes the card and reports it terminated', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('delete')->once()->with('/api/1.0/cards/card-1')->andReturn([]);

    $result = new TerminateCard($client)->terminate(revolutTerminateCommand());

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('card-1');
});

it('reports a failed result when termination fails', function () {
    $client = Mockery::mock(RevolutHttpClientInterface::class);
    $client->shouldReceive('delete')->once()->andThrow(new TransferException('not found'));

    $result = new TerminateCard($client)->terminate(revolutTerminateCommand());

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('not found');
});
