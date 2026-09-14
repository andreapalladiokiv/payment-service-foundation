<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Dispute\ValueObject;

use InvalidArgumentException;
use Techork\PaymentService\Domain\Dispute\Contract\DisputeAction;

/**
 * What a case is still waiting for: every action open on it, or the statement that none is.
 *
 * ## An empty set is a fact about the case, not about the provider's API
 *
 * This is the distinction the whole action model exists to keep, and it is worth stating in the type
 * that carries the answer: **{@see self::isEmpty()} being true means this dispute is not waiting on
 * us** — it was won, lost, accepted, expired, or challenging it is disallowed by the rules — and
 * never "the provider offers no API submission". ConnexPay has no API submission at all and answers
 * every case that is waiting for one with a {@see DashboardDisputeAction} carrying a link into its
 * portal; a provider for which `availableActions` is out of scope (Paynet, Revolut) has **no binding
 * for the port at all** rather than an implementation returning nothing, because an empty set would
 * be a wrong statement about every case rather than a missing feature.
 *
 * One state is an exception to that reading and is named rather than hidden: a case waiting on us
 * whose deadline we do not hold has no response task that can be built at all — both
 * {@see RespondDisputeAction} and {@see AcceptDisputeAction} require a non-nullable deadline — so it
 * answers empty. There, empty means "nothing is constructible to do here" rather than "not waiting
 * on us". Two guards keep it rare: the provider's own read refuses to exist without a deadline on a
 * case it reports as awaiting one, and the case's deadline is filled by the delivery that opens it
 * and kept current by {@see \Techork\PaymentService\Domain\Dispute\DisputeAggregate::changeDeadline()}
 * — which is exactly why the deadline may fall back to the recorded one where the evidence pair may
 * not (see that method's own docblock).
 *
 * ## Why a set and not a list
 *
 * One action per concrete type. Two responses open on the same case would leave the caller no basis
 * for choosing between them, and the provider's own vocabulary has one of each. A caller assembling
 * them twice — a re-read appended to an earlier answer — is a mapping bug, and it is refused here
 * rather than resolved by keeping whichever came last.
 *
 * The type is recovered by class-string rather than by a discriminator, which is why
 * {@see self::has()} and {@see self::get()} take a `class-string`: there is no `ActionType` to key
 * on, because the concrete class **is** the type.
 */
final readonly class DisputeActionSet
{
    /** @var list<DisputeAction> */
    private array $actions;

    /** @param array<array-key, DisputeAction> $actions */
    private function __construct(array $actions)
    {
        $this->actions = self::assertOnePerType($actions);
    }

    /**
     * Nothing is waiting on us.
     *
     * Named rather than spelled `of([])` so that the one answer with a meaning of its own is not
     * produced by a call that reads like "build me a set from this empty thing".
     */
    public static function none(): self
    {
        return new self([]);
    }

    /** @param array<array-key, DisputeAction> $actions */
    public static function of(array $actions): self
    {
        return new self($actions);
    }

    /** @return list<DisputeAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** Whether the case is not waiting on us — see the class docblock for the one exception. */
    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /**
     * @template T of DisputeAction
     *
     * @param  class-string<T>  $action
     */
    public function has(string $action): bool
    {
        return $this->get($action) !== null;
    }

    /**
     * The one action of this concrete type, or null.
     *
     * @template T of DisputeAction
     *
     * @param  class-string<T>  $action
     * @return T|null
     */
    public function get(string $action): ?DisputeAction
    {
        foreach ($this->actions as $candidate) {
            if ($candidate instanceof $action) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, DisputeAction>  $actions
     * @return list<DisputeAction>
     */
    private static function assertOnePerType(array $actions): array
    {
        $seen = [];

        foreach ($actions as $action) {
            $type = $action::class;

            array_key_exists($type, $seen) && throw new InvalidArgumentException(
                "An action set carries one action per type; {$type} arrived twice, which leaves the "
                . 'caller no basis for choosing between them.',
            );

            $seen[$type] = true;
        }

        return array_values($actions);
    }
}
