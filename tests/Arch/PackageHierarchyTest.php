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

/*
| The hierarchy above is about types, and one rule here is not about a type.
|
| A provider's own id for a person — `customer_reference`, `cus_...`, ConnexPay's
| `card.customer.guid` — belongs to `Gateway` and to its Laravel implementation,
| and nowhere else. The aggregate holds identity; the map holds identifiers. That
| is what keeps a customer of ours from acquiring a second, provider-shaped
| identity it would then have to reconcile — and it is why `CustomerForgotten`
| can leave those rows alone without the aggregate needing to know they exist.
|
| `toUse()` cannot check it. The concept crosses as a string — a column name, an
| array key, a property called `customerReference` — so there is no class to name
| and no import to forbid. A source scan is the only assertion that would fail if
| someone put the field on an event payload, and it is written here rather than
| beside the repository because this file is where the package boundaries are
| already enforced.
*/

arch('a provider-side customer reference never leaves the Gateway package', function (): void {
    $offenders = [];

    // Both spellings, because the two are the same fact in different clothes: the column and the
    // key are snake_case, the property and the parameter are camelCase, and either one appearing
    // in `Domain` or `Common` is the same leak.
    foreach (['Domain', 'Common'] as $package) {
        foreach (['src', 'tests'] as $directory) {
            $root = dirname(__DIR__, 2)."/src/{$package}/{$directory}";

            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (str_contains($contents, 'customer_reference') || str_contains($contents, 'customerReference')) {
                    $offenders[] = $package.'/'.$directory.'/'.$file->getFilename();
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'a provider\'s own id for a customer reached Domain or Common; it belongs to Gateway',
    );
});
