<?php

declare(strict_types=1);

/*
| The internal hierarchy only holds if the outside world respects it too. A
| package that reaches for a framework, an event store or a provider SDK it
| has no business knowing about has climbed a layer regardless of which
| Techork namespaces it imports.
|
| Each group below names the packages that legitimately own a vendor
| namespace; every other package is asserted not to touch it.
|
| Namespaces here must be spelled as composer PSR-4 prefixes, because that is
| how Pest resolves a dependency to a set of files. A shorter spelling — say
| `Nuvei` where the prefix is `Nuvei\Api` — resolves to nothing and passes for
| free. The last test in this file guards against that.
*/

$packages = [
    'Common', 'Domain', 'Gateway', 'Firewall', 'Forter', 'Neutrino',
    'ConnexPay', 'Nuvei', 'Paynet', 'Revolut', 'Stripe', 'Laravel',
];

$namespace = static fn (string $package): string => 'Techork\\PaymentService\\'.$package;

/**
 * @var array<string, array{owners: list<string>, vendor: list<string>}>
 */
$confined = [
    // Framework confinement is what makes the other eleven packages
    // installable without Laravel at all.
    'the framework stays in the bridge' => [
        'owners' => ['Laravel'],
        'vendor' => ['Illuminate', 'Carbon', 'Spatie\WebhookClient', 'Spatie\LaravelPackageTools'],
    ],

    // The event store is an implementation detail of the aggregates and of
    // the bridge that persists them. An adapter that reads it is reaching
    // past its port.
    'the event store stays under the domain' => [
        'owners' => ['Domain', 'Laravel'],
        'vendor' => ['EventSauce\EventSourcing', 'EventSauce\ObjectHydrator', 'EventSauce\Clock'],
    ],

    // Each provider SDK belongs to the one adapter that speaks that provider.
    'the Stripe SDK stays in the Stripe adapter' => [
        'owners' => ['Stripe'],
        'vendor' => ['Stripe'],
    ],
    'the Nuvei SDK stays in the Nuvei adapter' => [
        'owners' => ['Nuvei'],
        'vendor' => ['Nuvei\Api'],
    ],
];

/*
| The core carries no transport. Common and Domain describe what a payment is
| and what happens to it; the packages above them decide how bytes move.
*/
$transport = [
    'GuzzleHttp', 'GuzzleHttp\Psr7',
    'Psr\Http\Message', 'Psr\Http\Client',
    'Symfony\Contracts\HttpClient', 'Symfony\Component\HttpFoundation',
];

$confined['the core speaks no HTTP'] = [
    'owners' => array_values(array_diff($packages, ['Common', 'Domain'])),
    'vendor' => $transport,
];

foreach ($confined as $description => ['owners' => $owners, 'vendor' => $vendor]) {
    $outsiders = array_values(array_diff($packages, $owners));

    arch($description)
        ->expect(array_map($namespace, $outsiders))
        ->not->toUse($vendor);
}

/*
| The two bottom layers stated positively. Everything the core is allowed to
| know is on one line, so widening it is a deliberate act rather than a
| forgotten import. PHP's own classes are always allowed.
*/

arch('the shared vocabulary knows only phone numbers, uuids and locales')
    ->expect('Techork\PaymentService\Common')
    ->toOnlyUse([
        // Named as classes rather than namespaces: libphonenumber and Intl
        // both ship their datasets as PHP files, and naming the namespace
        // makes Pest parse every one of them. When this list is short of an
        // entry the failure says which class to add.
        'libphonenumber\PhoneNumber',
        'libphonenumber\PhoneNumberUtil',
        'libphonenumber\PhoneNumberFormat',
        'libphonenumber\NumberParseException',
        'Symfony\Component\Intl\Countries',
        'Ramsey\Uuid',
        'Techork\PaymentService\Common',
    ]);

arch('the domain knows only money, its event store and the shared vocabulary')
    ->expect('Techork\PaymentService\Domain')
    ->toOnlyUse([
        'Money',
        'EventSauce\EventSourcing',
        'Techork\PaymentService\Common',
        'Techork\PaymentService\Domain',
    ]);

/*
| Every namespace in the confinement map has to resolve to real files, or the
| assertion naming it is green for no reason: a `not->toUse` against an empty
| layer holds trivially and Pest cannot tell that apart from a clean package.
| The two `toOnlyUse` expectations need no such guard — an unresolvable entry
| there makes them fail, not pass.
*/

arch('every guarded namespace resolves to files on disk', function () use ($confined): void {
    $loader = null;

    foreach (spl_autoload_functions() ?: [] as $autoloader) {
        if (is_array($autoloader) && $autoloader[0] instanceof Composer\Autoload\ClassLoader) {
            $loader = $autoloader[0];
            break;
        }
    }

    expect($loader)->not->toBeNull('composer autoloader not found');

    $prefixes = array_map(
        static fn (string $prefix): string => rtrim($prefix, '\\'),
        array_keys($loader->getPrefixesPsr4()),
    );

    $guarded = array_unique(array_merge(...array_column($confined, 'vendor')));

    foreach ($guarded as $target) {
        $resolves = array_filter($prefixes, static fn (string $prefix): bool => str_starts_with($target, $prefix));

        expect($resolves)->not->toBeEmpty(
            "'$target' matches no composer PSR-4 prefix, so every assertion naming it passes vacuously",
        );
    }
});
