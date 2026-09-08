<?php

declare(strict_types=1);

use GuzzleHttp\Exception\TransferException;

it('builds void data with DeviceGuid and AuthOnlyGuid', function () {
    $data = cpVoid()->payload();

    expect($data['DeviceGuid'])->toBe('device-789')
        ->and($data['AuthOnlyGuid'])->toBe('auth-guid-xyz')
        ->and($data)->not->toHaveKey('OrderNumber');
});

it('forwards clientUniqueId as OrderNumber', function () {
    expect(cpVoid('ORD-42')->payload()['OrderNumber'])->toBe('ORD-42');
});

/**
 * Real ConnexPay sandbox response for POST /api/v1/Void of an AuthOnly,
 * captured on 2026-05-06. ConnexPay nests the original auth under "authOnly"
 * but keeps wasProcessed/guid/status at the top level — so no envelope unwrap
 * is needed for the success path.
 */
it('marks an approved Void response as successful', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')
        ->once()
        ->with('/api/v1/void', Mockery::on(fn (array $d): bool => $d['AuthOnlyGuid'] === 'auth-guid-xyz'))
        ->andReturn([
            'guid' => '8c9a88ba-e297-49bc-a0cc-8a1c337acc25',
            'status' => 'Transaction - Approved',
            'wasProcessed' => true,
            'amount' => 3.0,
            'authOnlyGuid' => '9265e8b3-6464-48d4-87dd-4a30300412e8',
            'authOnly' => [
                'guid' => '9265e8b3-6464-48d4-87dd-4a30300412e8',
                'status' => 'Transaction - Approved',
            ],
        ]);

    $result = cpVoid(client: $client)->cancel();

    expect($result->success)->toBeTrue()
        ->and($result->reference)->toBe('8c9a88ba-e297-49bc-a0cc-8a1c337acc25');
});

it('returns a failed result on a transport error', function () {
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andThrow(new TransferException('Network error'));

    $result = cpVoid(client: $client)->cancel();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('Network error');
});
