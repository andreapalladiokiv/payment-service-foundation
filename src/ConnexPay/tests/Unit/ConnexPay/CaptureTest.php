<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;
use Techork\PaymentService\ConnexPay\Capture;
use Techork\PaymentService\ConnexPay\ConnexPayHttpClientInterface;

/**
 * Payload below is the literal sale envelope embedded in a real
 * /api/v1/Captures response captured from sandbox on 2026-05-06.
 * The envelope is what {@see Capture::capture()} unwraps before mapping.
 *
 * @return array<string, mixed>
 */
function cpCaptureSaleEnvelope(): array
{
    return [
        'guid' => 'f3a4ea0b-a36b-4c04-83c9-e030428a325c',
        'status' => 'Transaction - Approved',
        'amount' => 5.0,
        'tenderType' => 'Credit',
        'wasProcessed' => true,
        'processorStatusCode' => 'A0000',
        'processorResponseMessage' => 'Success',
        'card' => [
            'first6' => '401200',
            'last4' => '5439',
            'cardType' => 'Visa',
            'expirationDate' => '2030-12',
            'guid' => '44d491d4-2c23-4493-9d95-eb7301c0afda',
        ],
        'addressVerificationCode' => '0',
        'cvvVerificationCode' => 'M',
    ];
}

it('builds capture data with DeviceGuid and AuthOnlyGuid', function () {
    $data = cpCapture()->payload();

    expect($data['DeviceGuid'])->toBe('device-123')
        ->and($data['AuthOnlyGuid'])->toBe('auth-guid-abc')
        ->and($data['ConnexPayTransaction']['ExpectedPayments'])->toBe(1)
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('forwards clientUniqueId as OrderNumber', function () {
    expect(cpCapture('ORD-42')->payload()['OrderNumber'])->toBe('ORD-42');
});

/**
 * The bridge ports suffix the aggregate id per operation; ConnexPay has no idempotency-key
 * semantics, so the suffix must not reach merchant-facing reports.
 */
it('strips the :capture suffix when forwarding clientUniqueId as OrderNumber', function () {
    expect(cpCapture('pi-uuid-7:capture')->payload()['OrderNumber'])->toBe('pi-uuid-7');
});

it('returns the sale guid (not the capture guid) as the reference', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/Captures', Mockery::any())
        ->andReturn([
            'guid' => '8c999755-908f-498b-b4b6-c17895a2f1c5',
            'deviceGuid' => 'd4d1267d-d619-4704-86cd-a9c6c3c1ec2c',
            'sale' => cpCaptureSaleEnvelope(),
        ]);

    $result = cpCapture(client: $client)->capture();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('f3a4ea0b-a36b-4c04-83c9-e030428a325c');
});

it('surfaces the incoming transaction code from the unwrapped sale', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn([
        'guid' => 'capture-guid',
        'sale' => [
            ...cpCaptureSaleEnvelope(),
            'connexPayTransaction' => ['incomingTransCode' => 'ICT-42'],
        ],
    ]);

    expect(cpCapture(client: $client)->capture()->metadata)
        ->toBe(['incoming_transaction_code' => 'ICT-42']);
});

/**
 * A capture settles an intent that was opened by the authorization it takes; writing an opening
 * reference here would bury the auth's under the sale's.
 */
it('does not claim to be the opening transaction', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(['sale' => cpCaptureSaleEnvelope()]);

    expect(cpCapture(client: $client)->capture()->metadata)->not->toHaveKey('opening_transaction_reference');
});

it('falls back to the top-level body when there is no sale envelope', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn(cpCaptureSaleEnvelope());

    expect(cpCapture(client: $client)->capture()->reference)->toBe('f3a4ea0b-a36b-4c04-83c9-e030428a325c');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    $result = cpCapture(client: $client)->capture();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Network error');
});
