<?php

declare(strict_types=1);

use Techork\PaymentService\Common\Contract\CodedError;
use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Techork\PaymentService\Domain\Checkout\Exception\CheckoutNotPayable;
use Techork\PaymentService\Domain\PaymentIntent\Exception\InvalidPaymentIntent;
use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCaptured;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;
use Techork\PaymentService\Domain\PaymentIntent\Refund\Exception\RefundNotFound;
use Techork\PaymentService\Domain\PaymentIntent\Refund\ValueObject\RefundId;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;

/**
 * The shared error vocabulary, and the wiring that keeps it from being decoration.
 *
 * A list of codes nobody attaches is worse than none: an application still maps exception class
 * names to strings by hand, still has to notice when a new class appears, and still fails silently
 * when it does not. So the guard that matters here is not "these codes exist" but "every refusal
 * carries one, and a new one cannot be written without".
 */
it('gives every refusal a code without anyone catching it', function (Throwable $refusal, ErrorCode $expected) {
    // One per family, spot-checking that the code says what the refusal is about rather than
    // which class raised it — two different guards on the same class answer differently, and two
    // classes about the same problem answer the same.
    expect($refusal)->toBeInstanceOf(CodedError::class)
        ->and($refusal->errorCode())->toBe($expected);
})->with([
    'wrong state' => [
        fn () => PaymentIntentCannotBeCaptured::withStatus(PaymentIntentStatus::Charged),
        ErrorCode::PaymentIntentUnexpectedState,
    ],
    'same class, different problem' => [
        fn () => PaymentIntentCannotBeCaptured::immediate(),
        ErrorCode::CaptureMethodUnsupported,
    ],
    'amount' => [
        fn () => InvalidPaymentIntent::nonPositiveAmount(),
        ErrorCode::InvalidChargeAmount,
    ],
    'instrument' => [
        fn () => InvalidPaymentIntent::unusablePaymentSource(),
        ErrorCode::PaymentMethodUnexpectedState,
    ],
    'missing resource' => [
        fn () => RefundNotFound::withId(RefundId::generate()),
        ErrorCode::ResourceMissing,
    ],
    'expiry, not state' => [
        fn () => CheckoutNotPayable::expired(),
        ErrorCode::CheckoutExpired,
    ],
    'another aggregate in the wrong state' => [
        fn () => CheckoutNotPayable::withStatus(Techork\PaymentService\Domain\Checkout\CheckoutStatus::Charged),
        ErrorCode::CheckoutUnexpectedState,
    ],
    'a payment intent named by a checkout' => [
        fn () => CheckoutNotPayable::paymentIntentNotAuthorized(PaymentIntentStatus::Failed),
        ErrorCode::PaymentIntentUnexpectedState,
    ],
    'capability' => [
        fn () => UnsupportedOperation::forGateway('nuvei', 'issueVirtualCard', 'no such product'),
        ErrorCode::UnsupportedByGateway,
    ],
]);

it('can be caught as one thing', function () {
    // The point of the interface extending Throwable. An application writes one catch and reads
    // the code, rather than an instanceof ladder that grows every time an aggregate learns a
    // guard — and forgetting to extend that ladder is silent.
    $caught = null;

    try {
        throw InvalidPaymentIntent::nonPositiveAmount();
    } catch (CodedError $e) {
        $caught = $e->errorCode();
    }

    expect($caught)->toBe(ErrorCode::InvalidChargeAmount);
});

it('refuses to answer for a refusal built without stating a code', function () {
    // These classes inherit a public constructor and PHP will not let it be hidden, so this call
    // stays reachable. It must not produce a code nobody chose — a wrong classification travels
    // to a merchant's retry logic, while this raises where it was written.
    expect(fn () => new InvalidPaymentIntent('hand-rolled')->errorCode())
        ->toThrow(Error::class, 'must not be accessed before initialization');
});

it('separates what was attempted from what was refused', function (ErrorCode $code, bool $attempted) {
    // The one thing a flat list cannot say on its own: whether there is a payment to read back or
    // whether nothing was created and the caller's own request is what to change. Kept as a method
    // so an SDK's common path stays a single switch over the code.
    expect($code->wasAttempted())->toBe($attempted);
})->with([
    [ErrorCode::AuthenticationRequired, true],
    [ErrorCode::AuthenticationFailed, true],
    [ErrorCode::Blocked, true],
    [ErrorCode::GatewayDeclined, true],
    [ErrorCode::PaymentIntentUnexpectedState, false],
    [ErrorCode::InvalidChargeAmount, false],
    [ErrorCode::ResourceMissing, false],
    [ErrorCode::UnsupportedByGateway, false],
    [ErrorCode::Unspecified, false],
]);

it('leaves no refusal able to skip the code', function () {
    // The structural half, and the one that survives someone adding a class this file forgot.
    // Every named constructor on a CodedError has to go through `coded()`; a bare `new self(...)`
    // or `new static(...)` builds a refusal whose `errorCode()` raises at the point a caller asks
    // — long after the code was written.
    $offenders = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 4))) as $file) {
        $path = $file->getPathname();

        // Production code only, and not the trait itself: `coded()` is the one place `new static`
        // belongs, being what every other site goes through.
        if ($file->getExtension() !== 'php'
            || str_contains($path, '/tests/')
            || str_ends_with($path, 'Concern/CarriesErrorCode.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // The marker interface for gateway capability refusals extends CodedError, so classes
        // naming it are in scope without naming CodedError themselves — using the trait is what
        // they all have in common.
        if (! str_contains($source, 'CarriesErrorCode')) {
            continue;
        }

        if (preg_match('/new (self|static)\(/', $source) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});
