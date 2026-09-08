<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Contract\AuthorizationResult;

/**
 * The 3DS step ConnexPay reports on a sale or auth-only, pinned against the payloads their own
 * reference publishes rather than against field names we hoped for.
 *
 * The mapping previously looked for a `threeDSecure` block with `acsUrl`, `cReq` and an
 * `authenticationStatus` of `Challenge`. None of those names appear anywhere in a ConnexPay
 * response. What arrives is HTTP 202 with a `status` of `3DS - Pending Fingerprint` or
 * `3DS - Pending User Challenge`, a `redirectUrl`, and a `redirectUrlRequestPayload` on the first
 * of the two.
 *
 * That was not a dormant branch, which is the reason these tests exist. A 202 body carries no
 * `wasProcessed`, so the payment reads as unsuccessful; with no challenge recognised either, the
 * caller had nothing to report but a refusal — so every ConnexPay payment the issuer wanted
 * authenticated was booked as an acquirer decline.
 *
 * The bodies below are their documented examples, trimmed of the card block. They are read
 * through {@see \Techork\PaymentService\ConnexPay\Authorize} because a response is no longer a
 * thing you can hold on its own: the operation maps what it got, once.
 *
 * @param  array<string, mixed>  $body
 */
function cxpChallengeResult(array $body): AuthorizationResult
{
    $client = cpHttpClient();
    $client->shouldReceive('post')->once()->andReturn($body);

    return cpAuthorize(['instrument' => cpCard()], ['client' => $client])->authorize();
}

/**
 * @return array<string, mixed>
 */
function cxpPendingFingerprint(): array
{
    return [
        'guid' => '92bcd4df-5576-48be-b4a6-8c142669a8b6',
        'status' => '3DS - Pending Fingerprint',
        'timeStamp' => '2022-11-28T19:49:13.7902906Z',
        'deviceGuid' => 'e639a1dc-5cc4-43de-ab74-d5bea6c6b107',
        'amount' => 1.0,
        'redirectUrl' => 'https://x3d-sim.credorax.net/acs/3ds-method',
        'redirectUrlRequestPayload' => 'threeDSMethodData=eyJ0aHJlZURTTWV0aG9kTm90aWZpY2F0aW9uVVJMIjoiaHR0cHM6Ly9zYWxlc2FwaS5jb25uZXhwYXlkZXYuY29tL2FwaS92MS8zZHMvY2FsbGJhY2siLCJ0aHJlZURTU2VydmVyVHJhbnNJRCI6ImI5M2MzODkyLTFiMjItNDFlOS1iZmE3LTdkNTMzNzYzMTExMiJ9',
    ];
}

it('reads the fingerprint step, url and payload both', function () {
    // The step that comes first and that nothing here used to see at all. The payload is the form
    // body verbatim, `threeDSMethodData=<base64>` — what the browser posts, not the base64 alone.
    $challenge = cxpChallengeResult(cxpPendingFingerprint())->challenge;

    expect($challenge)->not->toBeNull()
        ->and($challenge->authenticationId)->toBe('92bcd4df-5576-48be-b4a6-8c142669a8b6')
        ->and($challenge->url)->toBe('https://x3d-sim.credorax.net/acs/3ds-method')
        ->and($challenge->payload)->toStartWith('threeDSMethodData=');
});

it('reads the challenge step, which carries no payload', function () {
    // Same two field names, one of them absent — which is exactly why a challenge requires a url
    // and does not require a payload. A model with a field per step would have to guess which
    // step this is; a model with one pair does not have to know.
    $body = cxpPendingFingerprint();
    $body['status'] = '3DS - Pending User Challenge';
    unset($body['redirectUrlRequestPayload']);

    $challenge = cxpChallengeResult($body)->challenge;

    expect($challenge)->not->toBeNull()
        ->and($challenge->url)->toBe('https://x3d-sim.credorax.net/acs/3ds-method')
        ->and($challenge->payload)->toBeNull();
});

it('names the authentication by the transaction, which is stable across both steps', function () {
    // ConnexPay publishes no threeDSServerTransID field. The value is inside the base64 payload,
    // present on the fingerprint step only, and is not what resumes anything — the merchant
    // finishes the step and calls the same endpoint again against this transaction. Extracting it
    // was written and reverted: it would have named a different thing at each step.
    $second = cxpPendingFingerprint();
    $second['status'] = '3DS - Pending User Challenge';
    unset($second['redirectUrlRequestPayload']);

    expect(cxpChallengeResult(cxpPendingFingerprint())->challenge?->authenticationId)
        ->toBe(cxpChallengeResult($second)->challenge?->authenticationId);
});

it('reports no challenge for a transaction that is not waiting on one', function (array $body) {
    expect(cxpChallengeResult($body)->challenge)->toBeNull();
})->with([
    'approved' => [['guid' => 'g-1', 'status' => 'Transaction - Approved', 'wasProcessed' => true]],
    'no status at all' => [['guid' => 'g-1', 'wasProcessed' => true]],
    'pending but nowhere to send anyone' => [['guid' => 'g-1', 'status' => '3DS - Pending User Challenge']],
    'pending but unnameable' => [['status' => '3DS - Pending User Challenge', 'redirectUrl' => 'https://acs.test/x']],
]);

it('does not call a pending authentication a success', function () {
    // The other half of the old failure. A 202 body has no `wasProcessed`, so this is not a
    // success — correct on its own, and a refusal once the challenge went unrecognised. The
    // mapping asks for the challenge first, which is what makes the pair work.
    $result = cxpChallengeResult(cxpPendingFingerprint());

    expect($result->success)->toBeFalse()
        ->and($result->isRequiresAction())->toBeTrue();
});

/**
 * A step-up opens the intent as surely as an approval does, so the reference has to be recorded
 * as the opening one — the later confirm lands against this transaction.
 */
it('records the pending transaction as the one that opened the intent', function () {
    expect(cxpChallengeResult(cxpPendingFingerprint())->metadata)
        ->toHaveKey('opening_transaction_reference', '92bcd4df-5576-48be-b4a6-8c142669a8b6');
});
