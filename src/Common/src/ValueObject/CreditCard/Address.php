<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject\CreditCard;

use Techork\PaymentService\Common\Pii;
use Techork\PaymentService\Common\ShreddingStubs;
use Techork\PaymentService\Common\ValueObject\Country;
use Techork\PaymentService\Common\ValueObject\State;

final readonly class Address
{
    public function __construct(
        public string $city,
        public Country $country,
        public string $postalCode,
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $line,
        #[Pii(ShreddingStubs::ADDRESS_LINE)] public string $lineExtra = '',
        public ?State $state = null,
    ) {}
}
