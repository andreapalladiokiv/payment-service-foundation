<?php

declare(strict_types=1);

/*
| The subsplit packages form a strict hierarchy: Common at the bottom, the
| Laravel bridge at the top, and no edge that runs sideways or upwards. The
| neighbours listed for each package below are the ones it is allowed to
| reach; they mirror the internal `require` entries of that package's own
| composer.json, so when the two disagree one of them is a bug.
|
| Only the foundation can check this. Once a package is split out it no longer
| has its siblings on disk to be wrongly coupled to, and the composer graph
| alone cannot tell a declared dependency from a used one.
*/

$layers = [
    // The shared vocabulary. Owes nothing to anyone.
    'Common' => [],

    // Event-sourced aggregates and the ports they talk through.
    'Domain' => ['Common'],

    // The gateway abstraction every provider adapter implements.
    'Gateway' => ['Common'],

    // Rule engine driven by the domain's firewall port.
    'Firewall' => ['Common', 'Domain'],

    // Outbound service clients. Neither drives an aggregate.
    'Forter' => ['Common'],
    'Neutrino' => ['Common'],

    // Provider adapters. Siblings, never peers — each one knows Gateway and
    // Common and nothing about the other four, nor about the domain that
    // drives them.
    'ConnexPay' => ['Common', 'Gateway'],
    'Nuvei' => ['Common', 'Gateway'],
    'Paynet' => ['Common', 'Gateway'],
    'Revolut' => ['Common', 'Gateway'],
    'Stripe' => ['Common', 'Gateway'],

    // The bridge. Wires everything into a framework and is depended on by
    // nothing, which is what lets the rest stay framework-free.
    'Laravel' => ['Common', 'Domain', 'Gateway', 'Firewall'],
];

$namespace = static fn (string $package): string => 'Techork\\PaymentService\\'.$package;

$packages = array_keys($layers);

foreach ($layers as $package => $allowed) {
    $forbidden = array_values(array_diff($packages, [$package, ...$allowed]));

    $description = $allowed === []
        ? $package.' depends on no sibling package'
        : $package.' depends only on '.implode(', ', $allowed);

    arch($description)
        ->expect($namespace($package))
        ->not->toUse(array_map($namespace, $forbidden));
}

/*
| A package missing from the map above is a package with no place in the
| hierarchy, and its namespace would silently resolve to nothing — Pest
| cannot tell an empty layer from a clean one, so both assertions in this
| file would pass for it without checking anything. Compare the map against
| the autoloader instead.
*/

arch('every package is placed in the hierarchy', function () use ($layers): void {
    $loader = null;

    foreach (spl_autoload_functions() ?: [] as $autoloader) {
        if (is_array($autoloader) && $autoloader[0] instanceof Composer\Autoload\ClassLoader) {
            $loader = $autoloader[0];
            break;
        }
    }

    expect($loader)->not->toBeNull('composer autoloader not found');

    $autoloaded = [];

    foreach (array_keys($loader->getPrefixesPsr4()) as $prefix) {
        if (preg_match('/^Techork\\\\PaymentService\\\\([A-Za-z]+)\\\\$/', $prefix, $matches) !== 1) {
            continue;
        }

        // The suites of all twelve packages share one namespace; it is not a
        // package of its own.
        if ($matches[1] !== 'Tests') {
            $autoloaded[] = $matches[1];
        }
    }

    sort($autoloaded);

    $placed = array_keys($layers);
    sort($placed);

    expect($placed)->toBe(
        $autoloaded,
        'the hierarchy map and the autoloaded packages have drifted apart',
    );
});
