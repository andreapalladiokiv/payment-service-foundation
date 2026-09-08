<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\Customer;

use EventSauce\EventSourcing\AggregateRootBehaviour;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Snapshotting\AggregateRootWithSnapshotting;
use EventSauce\EventSourcing\Snapshotting\SnapshottingBehaviour;
use Override;
use Techork\PaymentService\Common\ValueObject\BillingAddress;
use Techork\PaymentService\Common\ValueObject\CustomerIdentity;
use Techork\PaymentService\Common\ValueObject\PaymentMethodId;
use Techork\PaymentService\Domain\Customer\Command\ForgetCustomerCommand;
use Techork\PaymentService\Domain\Customer\Command\RegisterCustomerCommand;
use Techork\PaymentService\Domain\Customer\Event\CustomerAddressChanged;
use Techork\PaymentService\Domain\Customer\Event\CustomerForgotten;
use Techork\PaymentService\Domain\Customer\Event\CustomerIdentityChanged;
use Techork\PaymentService\Domain\Customer\Event\CustomerRegistered;
use Techork\PaymentService\Domain\Customer\Event\PaymentMethodDetached;
use Techork\PaymentService\Domain\Customer\Event\PaymentMethodRegistered;
use Techork\PaymentService\Domain\Customer\Exception\CustomerForgottenException;
use Techork\PaymentService\Domain\Customer\Exception\PaymentMethodNotDetachable;
use Techork\PaymentService\Domain\Customer\Exception\PaymentMethodNotRegistrable;
use Techork\PaymentService\Domain\Customer\ValueObject\AttachedPaymentMethod;
use Techork\PaymentService\Domain\Customer\ValueObject\CustomerId;

/**
 * Who is paying, held once instead of reassembled from every payment.
 *
 * It exists because three gateway packages each invented a different way to have a customer
 * without one: Nuvei uses the payer's email as their identity, Stripe carries a
 * find-or-adopt-or-invent search to recover an identity nobody assigned, and ConnexPay sends
 * no customer at all. See `docs/customer-domain-plan` for the tally.
 *
 * **Deliberately thin: an id, who this is, and where they are.** Two jobs and no others —
 * carry identity to gateways, and own the payment methods registered to it (F1). No segments, no
 * order history, no consent, and above all **no identity resolution**: whether two records are
 * the same person is the host's policy, so the id arrives from the caller and this aggregate
 * refuses to guess.
 *
 * **No gateway references here.** `GatewayId` lives in a package `Domain` does not depend on,
 * so the type cannot even be named, and a string-keyed map of provider identifiers would be
 * the same infrastructure smuggled in untyped. Which customer a provider knows under which id
 * belongs to `Gateway\Contract\GatewayCustomerRepository`. The aggregate holds identity; the
 * map holds identifiers.
 *
 * The aggregate-root id type is bound on the `@use` below rather than here for the reason
 * {@see \Techork\PaymentService\Domain\Checkout\CheckoutAggregate} gives: EventSauce's
 * `AggregateRootWithSnapshotting` extends the generic `AggregateRoot` without carrying its
 * template forward.
 */
final class CustomerAggregate implements AggregateRootWithSnapshotting
{
    /** @use AggregateRootBehaviour<CustomerId> */
    use AggregateRootBehaviour;
    use SnapshottingBehaviour;

    private CustomerIdentity $identity;

    private ?BillingAddress $address = null;

    private CustomerStatus $status = CustomerStatus::Active;

    /**
     * The payment methods this customer can pay with, keyed by their id.
     *
     * The payment methods themselves, not references to them and not flags about them: this is
     * what owning them means, and it is what lets a card's billing address be the customer's rather
     * than a copy per card. Each entry is an {@see AttachedPaymentMethod} — the card paired with
     * the customer it belongs to — so ownership is a fact the entry carries rather than one implied
     * by which collection it sits in.
     *
     * Detaching removes the entry. Nothing is remembered about it, because the fact that a
     * provider will not take a released reference back is a fact about that reference — it
     * belongs to `GatewayCustomerRepository`, not here — and a genuinely new registration of the
     * same card arrives with a new id anyway.
     *
     * No timestamps: every message already carries
     * {@see \EventSauce\EventSourcing\Header::TIME_OF_RECORDING}, so when a card was added is
     * in the stream already and a copy here would be free to disagree.
     *
     * Tokens are never here — a {@see \Techork\PaymentService\Common\ValueObject\Token}
     * expires, and a collection of one-use handles would fill with dead entries.
     *
     * @var array<string, AttachedPaymentMethod>
     */
    private array $paymentMethods = [];

    #[Override]
    public function aggregateRootId(): CustomerId
    {
        return CustomerId::fromString($this->aggregateRootId->toString());
    }

    public function identity(): CustomerIdentity
    {
        return $this->identity;
    }

    public function address(): ?BillingAddress
    {
        return $this->address;
    }

    public function status(): CustomerStatus
    {
        return $this->status;
    }

    public function holds(PaymentMethodId $paymentMethodId): bool
    {
        return isset($this->paymentMethods[$paymentMethodId->toString()]);
    }

    /** @return array<string, AttachedPaymentMethod> */
    public function paymentMethods(): array
    {
        return $this->paymentMethods;
    }

    public static function register(RegisterCustomerCommand $command): self
    {
        $self = new self($command->customerId());
        $self->recordThat(new CustomerRegistered($command->identity()));

        return $self;
    }

    /**
     * Nothing is refused here, and that is the point.
     *
     * A customer with no email, no phone or a shared email is a customer. Today a missing
     * email decides whether one exists at all — it is the identity at Nuvei and was the gate
     * on creating one at Stripe — which made an optional field load-bearing. There is no
     * guard to add without putting that back.
     */
    public function changeIdentity(CustomerIdentity $identity): void
    {
        $this->status === CustomerStatus::Forgotten && throw CustomerForgottenException::cannotChange('identity');

        $this->recordThat(new CustomerIdentityChanged($identity));
    }

    /**
     * The card says whose it is, and this is where that is checked — by `CustomerId::equals()`,
     * against this aggregate's own id.
     *
     * Checking it at all is the point of {@see AttachedPaymentMethod}: a card offered to the wrong
     * customer would otherwise join the collection without complaint, and the collection would
     * then say it belongs to someone it does not.
     */
    public function registerPaymentMethod(AttachedPaymentMethod $attached): void
    {
        $this->status === CustomerStatus::Forgotten && throw CustomerForgottenException::cannotChange('payment methods');

        $attached->belongsTo($this->aggregateRootId())
            || throw PaymentMethodNotRegistrable::belongsToAnotherCustomer($attached->paymentMethod->id, $attached->customerId);

        $this->holds($attached->paymentMethod->id) && throw PaymentMethodNotRegistrable::alreadyRegistered($attached->paymentMethod->id);

        $this->recordThat(new PaymentMethodRegistered($attached));
    }

    public function detachPaymentMethod(PaymentMethodId $paymentMethodId): void
    {
        $this->holds($paymentMethodId) || throw PaymentMethodNotDetachable::notHeld($paymentMethodId);

        $this->recordThat(new PaymentMethodDetached($paymentMethodId));
    }

    public function changeAddress(BillingAddress $address): void
    {
        $this->status === CustomerStatus::Forgotten && throw CustomerForgottenException::cannotChange('address');

        $this->recordThat(new CustomerAddressChanged($address));
    }

    /**
     * Erase the identity and leave everything that points at this customer still pointing.
     *
     * The values go through the `#[Pii]` store; what stays is the id, the stubs, and a stream
     * that still replays. Deliberately NOT erased: the gateway reference map, because a
     * `cus_...` is not personal data and dropping it would orphan every payment method and payment
     * that names it.
     */
    public function forget(ForgetCustomerCommand $command): void
    {
        $this->recordThat(new CustomerForgotten($command->reason()));
    }

    #[Override]
    protected function createSnapshotState(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'address' => $this->address?->toArray(),
            'status' => $this->status->value,
            'payment_methods' => array_map(static fn (AttachedPaymentMethod $attached): array => $attached->toPayload(), $this->paymentMethods),
        ];
    }

    #[Override]
    protected static function reconstituteFromSnapshotState(AggregateRootId $id, $state): AggregateRootWithSnapshotting
    {
        // EventSauce's signature is the widest id type; a snapshot of this aggregate can only
        // carry its own.
        assert($id instanceof CustomerId);

        $self = new self($id);
        $self->identity = CustomerIdentity::fromArray($state['identity']);
        $self->address = isset($state['address']) ? BillingAddress::fromArray($state['address']) : null;
        $self->status = CustomerStatus::from($state['status'] ?? CustomerStatus::Active->value);
        $self->paymentMethods = array_map(AttachedPaymentMethod::fromPayload(...), $state['payment_methods'] ?? []);

        return $self;
    }

    protected function applyCustomerRegistered(CustomerRegistered $event): void
    {
        $this->identity = $event->identity;
    }

    protected function applyCustomerIdentityChanged(CustomerIdentityChanged $event): void
    {
        $this->identity = $event->identity;
    }

    protected function applyCustomerAddressChanged(CustomerAddressChanged $event): void
    {
        $this->address = $event->address;
    }

    protected function applyPaymentMethodRegistered(PaymentMethodRegistered $event): void
    {
        $this->paymentMethods[$event->attached->id()] = $event->attached;
    }

    protected function applyPaymentMethodDetached(PaymentMethodDetached $event): void
    {
        unset($this->paymentMethods[$event->paymentMethodId->toString()]);
    }

    /**
     * Erasure does not detach anything. A payment that was made was made, the card that
     * made it is not personal data on its own, and the card's own PAN is shredded through the
     * same `#[Pii]` store either way.
     */
    protected function applyCustomerForgotten(): void
    {
        $this->identity = CustomerIdentity::forgotten();
        $this->address = BillingAddress::unknown();
        $this->status = CustomerStatus::Forgotten;
    }
}
