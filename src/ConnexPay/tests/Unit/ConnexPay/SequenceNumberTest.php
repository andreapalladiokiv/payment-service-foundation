<?php

declare(strict_types=1);

/**
 * `OrderNumber` was doing duty as ConnexPay's idempotency key, and it has never been one.
 * Sent twice with the same order number and the same amount, an auth-only produces two
 * separate authorizations — two holds on one cardholder's card. ConnexPay's own duplicate
 * detection reads `SequenceNumber`, which this package did not send at all.
 *
 * The two fields want opposite things from the suffix the bridge ports add. `OrderNumber`
 * is a reporting and chargeback key that must stay the same across the auth, the capture
 * and the void of one payment — ConnexPay's capture even overwrites the auth's with the
 * one it is given — so the suffix is stripped from it. `SequenceNumber` identifies a single
 * request, so the suffix is exactly what it needs to keep.
 */
it('gives the capture a different sequence number from the authorization it settles', function () {
    $data = cpCapture('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:capture')->payload();

    expect($data['SequenceNumber'])->toBe('0199f0a21c3a7b8d9e4faabbccddeeffcapture')
        // The order number keeps the two together, because that is what reporting and the
        // chargeback system read.
        ->and($data['OrderNumber'])->toBe('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff');
});

it('repeats the sequence number when the same operation is retried', function () {
    $first = cpCapture('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:capture')->payload();
    $retry = cpCapture('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:capture')->payload();

    expect($retry['SequenceNumber'])->toBe($first['SequenceNumber']);
});

it('keeps each operation on one payment distinct', function () {
    $capture = cpCapture('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:capture')->payload();
    $void = cpVoid('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:cancel')->payload();

    expect($void['SequenceNumber'])->not->toBe($capture['SequenceNumber']);
});

/**
 * The field takes at most 100 alpha-numeric characters, and its description lists no
 * permitted punctuation — unlike `OrderNumber`, which names `[._/-]`. So the hyphens of a
 * UUID and the colon of the suffix come out.
 */
it('sends nothing ConnexPay does not accept in the field', function () {
    $sequence = cpCapture('0199f0a2-1c3a-7b8d-9e4f-aabbccddeeff:capture')->payload()['SequenceNumber'];

    expect($sequence)->toMatch('/^[A-Za-z0-9]+$/')
        ->and(strlen($sequence))->toBeLessThanOrEqual(100);
});

it('omits the field when the caller named no operation', function () {
    expect(cpCapture()->payload())->not->toHaveKey('SequenceNumber');
});
