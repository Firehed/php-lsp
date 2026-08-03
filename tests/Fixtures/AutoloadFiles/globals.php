<?php

declare(strict_types=1);

/**
 * A global-namespace `autoload.files` entry, covering the three ways a constant
 * reaches the global namespace: a `const` declaration, a `define()` with a literal
 * name, and a `define()` whose name is computed.
 *
 * The computed one is deliberately unreachable: its name exists only at runtime, so
 * a static parse cannot know it (Plan 0002 §3b, §3's locate-only limitation). It is
 * here so a test can prove the limitation holds rather than leave it asserted only
 * in prose.
 */

const FIXTURE_GLOBAL_LIMIT = 100;

define('FIXTURE_DEFINED_LIMIT', 200);

define('FIXTURE_COMPUTED_' . 'LIMIT', 300);

function fixtureGlobalHelper(int $value): int
{
    return $value * 2;
}
