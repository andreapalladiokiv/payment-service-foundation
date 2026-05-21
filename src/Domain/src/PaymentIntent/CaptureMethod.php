<?php

declare(strict_types=1);

namespace Techork\PaymentService\Domain\PaymentIntent;

enum CaptureMethod: string
{
    case Immediate = 'immediate';
    case Automatic = 'automatic';
    case Manual = 'manual';
}
