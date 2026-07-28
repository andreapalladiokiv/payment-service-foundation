<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Dsl;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;

/**
 * The whitelist of functions available inside authored rule expressions.
 *
 * This is the sandbox: Symfony ExpressionLanguage cannot call arbitrary PHP, so
 * an authored rule reaches only the operators, literals, the fact variables the
 * caller supplied, and these approved helpers. Adding a function here widens
 * what every rule author can do — treat it as a security decision.
 */
final class ExpressionFunctions
{
    /**
     * @return array<int, ExpressionFunction>
     */
    public static function all(): array
    {
        return [
            // NB: `contains`, `in`, `matches`, `starts with` and `ends with` are
            // native ExpressionLanguage operators — do not shadow them with a
            // function. `includes` is our own membership/substring helper that
            // works for both arrays and strings.
            new ExpressionFunction(
                'includes',
                static fn (string $haystack, string $needle): string => sprintf('includes(%s, %s)', $haystack, $needle),
                static function (array $variables, mixed $haystack, mixed $needle): bool {
                    if (is_array($haystack)) {
                        return in_array($needle, $haystack, true);
                    }

                    return $haystack !== null && str_contains((string) $haystack, (string) $needle);
                },
            ),
            new ExpressionFunction(
                'is_empty',
                static fn (string $value): string => sprintf('is_empty(%s)', $value),
                static fn (array $variables, mixed $value): bool => $value === null || $value === '' || $value === [],
            ),
            new ExpressionFunction(
                'is_not_empty',
                static fn (string $value): string => sprintf('is_not_empty(%s)', $value),
                static fn (array $variables, mixed $value): bool => ! ($value === null || $value === '' || $value === []),
            ),
        ];
    }
}
