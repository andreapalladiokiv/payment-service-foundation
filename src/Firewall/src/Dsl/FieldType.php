<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

/**
 * The declared type of a fact, used to coerce an authored literal before it is
 * compared against the fact's real PHP value.
 *
 * This matters because rules are authored as text (a form field, a config
 * string) while facts are real booleans, ints and floats. Without coercion
 * `is_proxy == "false"` compares against a non-empty string and is therefore
 * true — the rule silently inverts. {@see Mixed} is the fallback when a path
 * has no declared type; no coercion is applied then.
 */
enum FieldType
{
    case Text;
    case Number;
    case Boolean;
    case ListType;
    case Mixed;
}
