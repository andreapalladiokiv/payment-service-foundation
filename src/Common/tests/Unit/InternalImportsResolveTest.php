<?php

declare(strict_types=1);

/**
 * Every `use Techork\PaymentService\...` import in the bundle must resolve
 * through the autoloader. A binding to a class that was never committed
 * (e.g. `NoOpGatewayPaymentMethodRecorder` referenced by the bridge's
 * WebhookServiceProvider) survives static analysis of the consuming app and
 * only explodes at container-resolution time in production — catch it here.
 */
it('resolves every internal class imported anywhere in the bundle', function () {
    $root = dirname(__DIR__, 4);

    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src")),
        '/\/src\/[^\/]+\/src\/.*\.php$/',
    );

    $missing = [];

    foreach ($files as $file) {
        preg_match_all(
            '/^use\s+(Techork\\\\PaymentService\\\\[\w\\\\]+)/m',
            (string) file_get_contents((string) $file),
            $matches,
        );

        foreach ($matches[1] as $class) {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class) && ! trait_exists($class)) {
                $missing[$class][] = (string) $file;
            }
        }
    }

    expect($missing)->toBe([]);
});
