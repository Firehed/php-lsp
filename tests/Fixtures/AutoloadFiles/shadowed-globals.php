<?php

declare(strict_types=1);

/**
 * Redeclares names that `globals.php` already declares, so a test can pin *which* of
 * two `autoload.files` entries the index attributes a name to.
 *
 * Deliberately absent from `composer.json`'s `files` list, and never executed: PHP
 * cannot load two files declaring the same function, which is precisely why an entry
 * appearing twice means a stale map rather than an override, and why the first
 * declaration is the one that wins. This file is read only as data, by a hand-built
 * ComposerAutoloadMap that lists it after `globals.php`.
 */

const FIXTURE_GLOBAL_LIMIT = 999;

function fixtureGlobalHelper(int $value): int
{
    return $value;
}
