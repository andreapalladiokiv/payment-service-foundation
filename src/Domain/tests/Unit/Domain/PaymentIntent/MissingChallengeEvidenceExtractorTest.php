<?php

declare(strict_types=1);

use Techork\PaymentService\Common\ValueObject\Challenge\RedirectResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;
use Techork\PaymentService\Domain\PaymentIntent\MissingChallengeEvidenceExtractor;

/**
 * The aggregate reaches this extractor only after ChallengeFailureReasonExtractor has
 * already sent every refusal down the failure path, so its refusal branch is defensive and
 * unreachable from that direction. Tested directly for exactly that reason: the guard has
 * to keep holding if the order of those two checks is ever changed, and through the
 * aggregate no test could tell whether it still does.
 */
function evidenceIn(ThreeDSStatus $status, ?string $authenticationValue): ?string
{
    return new MissingChallengeEvidenceExtractor()->visitThreeDS(new ThreeDSResult(
        $status,
        $authenticationValue,
        ECICode::VisaSuccessful,
        '11111111-1111-1111-1111-111111111111',
        '22222222-2222-2222-2222-222222222222',
        ThreeDSVersion::V220,
    ));
}

it('describes a success status that carries no authentication value', function (ThreeDSStatus $status) {
    // Y, A and I all come back with a value — Y because the cardholder authenticated, A as
    // the proof of the attempt, I because the informational answer still carries one. An
    // empty one claims a liability shift while showing no evidence of it.
    expect(evidenceIn($status, null))->toBe("3DS authentication reported {$status->value} without an authentication value")
        ->and(evidenceIn($status, ''))->toBe("3DS authentication reported {$status->value} without an authentication value");
})->with([
    [ThreeDSStatus::Successful],
    [ThreeDSStatus::NotAvailable],
    [ThreeDSStatus::Info],
]);

it('accepts a success status that carries one', function (ThreeDSStatus $status) {
    expect(evidenceIn($status, 'cavv-base64'))->toBeNull();
})->with([
    [ThreeDSStatus::Successful],
    [ThreeDSStatus::NotAvailable],
    [ThreeDSStatus::Info],
]);

it('says nothing about a refusal, which is the other extractor\'s business', function () {
    // A refusal with no cryptogram is exactly what a refusal looks like. Reporting it here
    // as incoherent would turn the issuer's "no" into our own integrity error, and the
    // aggregate would stop recording declines as declines.
    expect(evidenceIn(ThreeDSStatus::Rejected, null))->toBeNull()
        ->and(evidenceIn(ThreeDSStatus::Rejected, 'cavv-base64'))->toBeNull();
});

it('has nothing to say about a redirect result', function () {
    // Its evidence is the transaction it names, and the type already requires a non-empty
    // one, so there is no incoherent RedirectResult to describe.
    expect(new MissingChallengeEvidenceExtractor()->visitRedirect(new RedirectResult('gw-ref')))->toBeNull();
});
