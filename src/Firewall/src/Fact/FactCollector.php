<?php

declare(strict_types=1);

namespace Techork\PaymentService\Firewall\Fact;

use Psr\Log\LoggerInterface;
use Techork\PaymentService\Common\Contract\FactSupplier;
use Throwable;

/**
 * Merges the facts of several {@see FactSupplier}s into one tree.
 *
 * Suppliers are usually remote lookups — a fraud screening call, a BIN lookup —
 * so one failing must never take the assessment down with it. A supplier that
 * throws contributes nothing and is logged; the chain then evaluates against the
 * facts that are known, and rules referencing the missing ones simply do not
 * match. That is the fail-soft behaviour the provider contracts already
 * prescribe, enforced in one place.
 *
 * Later suppliers win on a leaf collision, so ordering expresses precedence: put
 * the better source last.
 */
final readonly class FactCollector
{
    /**
     * @param  iterable<int, FactSupplier>  $suppliers
     */
    public function __construct(
        private iterable $suppliers,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $facts = [];

        foreach ($this->suppliers as $supplier) {
            try {
                $facts = self::merge($facts, $supplier->facts());
            } catch (Throwable $e) {
                $this->logger->warning('Firewall fact supplier failed; its facts are absent', [
                    'supplier' => $supplier::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $facts;
    }

    /**
     * Deep-merge, leaves from $extra winning.
     *
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function merge(array $facts, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $facts[$key] = is_array($value) && is_array($facts[$key] ?? null)
                ? self::merge($facts[$key], $value)
                : $value;
        }

        return $facts;
    }
}
