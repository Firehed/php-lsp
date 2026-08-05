<?php

declare(strict_types=1);

/**
 * A global-namespace `autoload.files` entry, covering the ways a constant reaches
 * the global namespace: a `const` declaration, several names in one `const`
 * statement, a `define()` with a literal name, a `define()` spelled in another case,
 * and a `define()` whose name is computed.
 *
 * The computed one is deliberately unreachable: its name exists only at runtime, so
 * a static parse cannot know it (Plan 0002 §3b, §3's locate-only limitation). It is
 * here so a test can prove the limitation holds rather than leave it asserted only
 * in prose.
 */

const FIXTURE_GLOBAL_LIMIT = 100;

// One statement, several constants: a scanner reading only the first declarator
// would silently drop the rest.
const FIXTURE_GLOBAL_ALPHA = 1, FIXTURE_GLOBAL_BETA = 2;

define('FIXTURE_DEFINED_LIMIT', 200);

// `define` is a function, and function names are case-insensitive, so this declares
// a constant exactly as the lowercase spelling above does.
DEFINE('FIXTURE_UPPERCASE_DEFINED_LIMIT', 250);

define('FIXTURE_COMPUTED_' . 'LIMIT', 300);

// `define` takes named arguments like any other function, and a named argument may
// be written in any order — so position alone does not say which one is the name.
define(constant_name: 'FIXTURE_NAMED_LIMIT', value: 400);
// A string value in first position is the case that misleads: read positionally it
// looks exactly like a name.
define(value: 'FIXTURE_NOT_A_CONSTANT_NAME', constant_name: 'FIXTURE_REORDERED_LIMIT');

// Not a declaration: a first-class callable makes a Closure and defines nothing.
define(...);

class FixtureGlobalRegistry
{
}

// An anonymous class has no name to index, so it is not a declaration.
$fixtureAnonymous = new class () {
};

function fixtureGlobalHelper(int $value): int
{
    return $value * 2;
}

// The shape most real `autoload.files` entries take: a polyfill declares itself only
// where the runtime lacks it. The declaration is therefore nested rather than
// top-level, which a scanner that walked only top-level statements would miss.
if (!function_exists('fixtureConditionalHelper')) {
    function fixtureConditionalHelper(int $value): int
    {
        return $value + 1;
    }
}

// Declarations inside a function body. Whether they happen is a runtime question —
// an immediately-invoked bootstrap closure declares them, this never-called
// function does not — so the scan reports them either way, on the lexical rule.
function fixtureBootstrap(): void
{
    define('FIXTURE_BODY_LIMIT', 500);

    function fixtureNestedHelper(int $value): int
    {
        return $value - 1;
    }
}
