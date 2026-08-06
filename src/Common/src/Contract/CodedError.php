<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\Contract;

use Techork\PaymentService\Common\ValueObject\ErrorCode;
use Throwable;

/**
 * A refusal that says, in machine terms, what it refused.
 *
 * Extends `Throwable` so it can be caught: an application turning our refusals into API responses
 * writes one `catch (CodedError $e)` and reads `errorCode()`, instead of a ladder of `instanceof`
 * over a dozen exception classes that grows every time an aggregate learns a new guard.
 *
 * That ladder is what this replaces, and the enum alone would not have. A shared list of codes
 * nobody attaches is decoration: the application still maps class names to strings by hand, still
 * has to notice when a new class appears, and still gets it wrong silently when it does not.
 *
 * `errorCode()` rather than `code()` because `Throwable::getCode()` already exists, returns an
 * int, and is 0 on every exception here — two similarly named accessors returning different types
 * is a trap worth one extra word.
 */
interface CodedError extends Throwable
{
    public function errorCode(): ErrorCode;
}
