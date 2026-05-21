<?php

declare(strict_types=1);

// Stub the EventSauce interface when not available (eventsauce is not a dependency of the common package)
if (! interface_exists(\EventSauce\EventSourcing\Serialization\SerializablePayload::class)) {
    eval('namespace EventSauce\EventSourcing\Serialization; interface SerializablePayload { public function toPayload(): array; public static function fromPayload(array $payload): static; }');
}

use Techork\PaymentService\Common\ValueObject\ThreeDS\ECICode;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSStatus;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSVersion;

function makeThreeDSResult(
    ThreeDSStatus $status = ThreeDSStatus::Successful,
    ?string $authenticationValue = 'cavv-value-abc',
    ?ECICode $eci = ECICode::VisaSuccessful,
    string $dsTransactionId = 'ds-txn-123',
    string $acsTransactionId = 'acs-txn-456',
    ?ThreeDSVersion $version = ThreeDSVersion::V220,
): ThreeDSResult {
    return new ThreeDSResult($status, $authenticationValue, $eci, $dsTransactionId, $acsTransactionId, $version);
}

// ──────────────────────────────────────────────
//  Serialization round-trip
// ──────────────────────────────────────────────

it('serializes to payload and back', function () {
    $original = makeThreeDSResult();
    $payload = $original->toPayload();

    expect($payload)->toBe([
        'status' => 'Y',
        'authentication_value' => 'cavv-value-abc',
        'eci' => '05',
        'ds_transaction_id' => 'ds-txn-123',
        'acs_transaction_id' => 'acs-txn-456',
        'version' => '2.2.0',
    ]);

    $restored = ThreeDSResult::fromPayload($payload);

    expect($restored->status)->toBe(ThreeDSStatus::Successful)
        ->and($restored->authenticationValue)->toBe('cavv-value-abc')
        ->and($restored->eci)->toBe(ECICode::VisaSuccessful)
        ->and($restored->dsTransactionId)->toBe('ds-txn-123')
        ->and($restored->acsTransactionId)->toBe('acs-txn-456')
        ->and($restored->version)->toBe(ThreeDSVersion::V220);
});

// ──────────────────────────────────────────────
//  Nullable eci and version
// ──────────────────────────────────────────────

it('handles nullable eci and version', function () {
    $result = makeThreeDSResult(eci: null, version: null);
    $payload = $result->toPayload();

    expect($payload['eci'])->toBeNull()
        ->and($payload['version'])->toBeNull();

    $restored = ThreeDSResult::fromPayload($payload);

    expect($restored->eci)->toBeNull()
        ->and($restored->version)->toBeNull()
        ->and($restored->status)->toBe(ThreeDSStatus::Successful)
        ->and($restored->authenticationValue)->toBe('cavv-value-abc');
});

it('handles nullable authentication value', function () {
    $result = makeThreeDSResult(authenticationValue: null);
    $payload = $result->toPayload();

    expect($payload['authentication_value'])->toBeNull();

    $restored = ThreeDSResult::fromPayload($payload);

    expect($restored->authenticationValue)->toBeNull();
});

// ──────────────────────────────────────────────
//  ThreeDSStatus enum values
// ──────────────────────────────────────────────

it('has all ThreeDSStatus enum values', function (ThreeDSStatus $status, string $value) {
    expect($status->value)->toBe($value);
})->with([
    'Successful' => [ThreeDSStatus::Successful, 'Y'],
    'NotAvailable' => [ThreeDSStatus::NotAvailable, 'A'],
    'NotAuthenticated' => [ThreeDSStatus::NotAuthenticated, 'N'],
    'NotPerformed' => [ThreeDSStatus::NotPerformed, 'U'],
    'Rejected' => [ThreeDSStatus::Rejected, 'R'],
]);

it('does not expose ChallengeRequired as a result status', function () {
    expect(ThreeDSStatus::tryFrom('C'))->toBeNull();
});

it('round-trips each ThreeDSStatus through payload', function (ThreeDSStatus $status) {
    $result = makeThreeDSResult(status: $status);
    $restored = ThreeDSResult::fromPayload($result->toPayload());

    expect($restored->status)->toBe($status);
})->with([
    'Successful' => [ThreeDSStatus::Successful],
    'NotAvailable' => [ThreeDSStatus::NotAvailable],
    'NotAuthenticated' => [ThreeDSStatus::NotAuthenticated],
    'NotPerformed' => [ThreeDSStatus::NotPerformed],
    'Rejected' => [ThreeDSStatus::Rejected],
]);

// ──────────────────────────────────────────────
//  ECICode enum values
// ──────────────────────────────────────────────

it('has all ECICode enum values', function (ECICode $eci, string $value) {
    expect($eci->value)->toBe($value);
})->with([
    'MastercardFailed' => [ECICode::MastercardFailed, '00'],
    'MastercardAttempted' => [ECICode::MastercardAttempted, '01'],
    'MastercardSuccessful' => [ECICode::MastercardSuccessful, '02'],
    'VisaSuccessful' => [ECICode::VisaSuccessful, '05'],
    'VisaAttempted' => [ECICode::VisaAttempted, '06'],
    'VisaFailed' => [ECICode::VisaFailed, '07'],
]);

it('round-trips each ECICode through payload', function (ECICode $eci) {
    $result = makeThreeDSResult(eci: $eci);
    $restored = ThreeDSResult::fromPayload($result->toPayload());

    expect($restored->eci)->toBe($eci);
})->with([
    'MastercardFailed' => [ECICode::MastercardFailed],
    'MastercardAttempted' => [ECICode::MastercardAttempted],
    'MastercardSuccessful' => [ECICode::MastercardSuccessful],
    'VisaSuccessful' => [ECICode::VisaSuccessful],
    'VisaAttempted' => [ECICode::VisaAttempted],
    'VisaFailed' => [ECICode::VisaFailed],
]);

// ──────────────────────────────────────────────
//  ThreeDSVersion enum values
// ──────────────────────────────────────────────

it('has ThreeDSVersion V220', function () {
    expect(ThreeDSVersion::V220->value)->toBe('2.2.0');
});
