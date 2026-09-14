<?php

declare(strict_types=1);

namespace Techork\PaymentService\ConnexPay\Dispute;

use RuntimeException;

/**
 * A CMS case that cannot be turned into a snapshot, refused by name rather than guessed at.
 *
 * ## Why a refusal and not a default
 *
 * Every other provider code in this adapter has somewhere to go when it is unrecognised: an
 * unmapped `CaseType` travels as the raw code and is listed on {@see PollResult} for an operator,
 * an absent `WinLoss` simply produces no status. These two do not, because the shape they feed has
 * no room for "unknown":
 *
 * - **`CardBrand` 5 is PayPal**, which ConnexPay states on its cases, and
 *   `Common\ValueObject\CardBrand` has no such case — deliberately: that enum is derived from a
 *   card number and PayPal is not a card network. The reference says to map it to a typed refusal
 *   rather than to a guess, and a guess here would be a *wrong card network* on a case whose
 *   evidence requirements (F6) are keyed on the network: the project would be handed the wrong
 *   template, and Visa's rules would be applied to a PayPal dispute. The same refusal covers a
 *   brand number the documentation does not list at all, which is a payload this adapter does not
 *   understand.
 * - **A case with no `CaseNumber`** has no identity. Every recorder call is addressed by it and
 *   every stored hash is keyed on it; accepting one would mean reporting a case that cannot be
 *   referred to again, and storing a hash under a key that matches nothing.
 *
 * A blank `ReasonCode` is refused for the same reason: {@see \Techork\PaymentService\Gateway\Webhook\Recorder\DisputeSnapshot}
 * will not accept one either, and half of the (brand, code) pair F6 keys evidence on cannot be
 * invented here.
 *
 * ## What the poller does with it
 *
 * Refuses the case, records it on {@see PollResult::$failures} with the case number and the
 * provider's own value, and **does not store its hash** — so the case is reported again on every
 * poll whose window still covers it rather than being quietly dropped, while the rest of the
 * window is read normally. One unrepresentable case does not stop a cycle: the alternative, a
 * thrown exception, would turn one PayPal dispute into a poller that never completes again.
 */
final class UnrepresentableCase extends RuntimeException
{
    public static function cardBrand(int|float|string|null $value): self
    {
        return new self(sprintf(
            'ConnexPay states CardBrand %s on this case. 1-4 are Visa, Mastercard, Discover and Amex, '
            .'and 5 is PayPal, which has no counterpart in Common\\ValueObject\\CardBrand — that enum '
            .'is a card network and PayPal is not one. Guessing a network would apply the wrong '
            .'network\'s evidence rules to the dispute, so the case is reported as refused instead.',
            $value === null ? '(absent)' : (string) $value,
        ));
    }

    public static function missingIdentity(string $field): self
    {
        return new self(sprintf(
            'The case carries no %s. It is the identity every recorder call and every stored hash is '
            .'keyed on, so a case without it cannot be reported or recognised on the next poll.',
            $field,
        ));
    }

    public static function unreadableDate(string $field, string $value): self
    {
        return new self(sprintf(
            'The case carries a %s of "%s", which is not a date this adapter can read. Reporting the '
            .'case with no deadline would present a case with a response window as one without, so it '
            .'is refused instead.',
            $field,
            $value,
        ));
    }
}
