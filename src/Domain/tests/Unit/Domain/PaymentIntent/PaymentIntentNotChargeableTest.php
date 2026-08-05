<?php

declare(strict_types=1);

use Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentNotChargeable;
use Techork\PaymentService\Domain\PaymentIntent\PaymentIntentStatus;

/**
 * The refusal a charge gets when the intent's status does not allow one. Sibling refusals
 * ({@see \Techork\PaymentService\Domain\PaymentIntent\Exception\PaymentIntentCannotBeCaptured},
 * ...CannotBeCancelled) are covered where the aggregate throws them; this one is thrown
 * outside the domain package, so its named constructor had no test at all and its message —
 * the only thing it carries — was never built once.
 *
 * Two things are pinned. The message names the offending status, because that string is what
 * an operator reads when a charge is refused and "cannot be charged" without the status does
 * not say whether to retry, re-authenticate or give up. And the lineage is DomainException:
 * callers separate a business refusal from a provider or transport failure by catching that,
 * so re-parenting this class would turn a refusal into a 500.
 */
it('names the status that refused the charge', function (PaymentIntentStatus $status, string $expected) {
    expect(PaymentIntentNotChargeable::withStatus($status)->getMessage())->toBe($expected);
})->with([
    // Every case of the enum, so a new status cannot be added without a message being
    // considered for it — an unrecognised one would otherwise surface as an empty bracket.
    [PaymentIntentStatus::RequiresAction, 'PaymentIntent cannot be charged in status [requires_action].'],
    [PaymentIntentStatus::Authorized, 'PaymentIntent cannot be charged in status [authorized].'],
    [PaymentIntentStatus::Charged, 'PaymentIntent cannot be charged in status [charged].'],
    [PaymentIntentStatus::Failed, 'PaymentIntent cannot be charged in status [failed].'],
    [PaymentIntentStatus::Cancelled, 'PaymentIntent cannot be charged in status [cancelled].'],
]);

it('is a domain refusal rather than a failure, which is how callers tell them apart', function () {
    $refusal = PaymentIntentNotChargeable::withStatus(PaymentIntentStatus::Charged);

    expect($refusal)->toBeInstanceOf(DomainException::class)
        ->and($refusal->getCode())->toBe(0)
        ->and($refusal->getPrevious())->toBeNull();
});
