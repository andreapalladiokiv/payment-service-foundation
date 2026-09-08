<?php

declare(strict_types=1);

namespace Techork\PaymentService\Revolut;

/**
 * What the gateway's configuration contributes to a card request, as opposed to what the caller
 * asked for.
 *
 * The split matters because these are deployment facts — which card programme, which accounts the
 * card may draw on, how long it lives — and none of them belong in a command. They used to reach
 * the request as five more keys in the same array as the amount, which is why the request had five
 * accessors that could not tell you where a value had come from.
 */
final readonly class CardSettings
{
    /**
     * @param  ?list<string>  $accountIds  the `accounts` allow-list; absent means the business
     *   default account
     */
    public function __construct(
        public ?string $product = null,
        public ?array $accountIds = null,
        public string $spendLimitPeriod = 'single',
        public ?int $validityDays = null,
        public bool $fetchSensitiveDetails = true,
    ) {}
}
