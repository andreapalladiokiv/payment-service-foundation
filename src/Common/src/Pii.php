<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Pii
{
    public function __construct(public mixed $stub) {}
}
