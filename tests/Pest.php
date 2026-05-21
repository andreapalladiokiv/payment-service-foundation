<?php

declare(strict_types=1);

/*
| Aggregate per-package Pest.php files so their bindings and helper functions
| are available when the suite runs from the foundation root.
*/

foreach (glob(__DIR__.'/../src/*/tests/Pest.php') as $packagePest) {
    require_once $packagePest;
}
