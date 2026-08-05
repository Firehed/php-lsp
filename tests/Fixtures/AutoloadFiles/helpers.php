<?php

declare(strict_types=1);

namespace Fixtures\Helpers;

/**
 * A namespaced `autoload.files` entry: functions and constants that no PSR-4
 * name->file map can reach, because they are not class-likes. This file is the
 * bounded, explicit reach the plan scopes function/constant lookup to (Plan 0002
 * §3), so it backs the locator's index rather than any class-like query.
 */

const HELPER_LIMIT = 25;

// `define()` is a function call, so the namespace it is written in does not reach
// its argument: the literal is the whole name. Both spellings below declare exactly
// what they say, one global and one qualified.
define('FIXTURE_HELPER_DEFINED', 30);
define('Fixtures\Helpers\HELPER_DEFINED_QUALIFIED', 35);

// Every class-like flavour, declared in a `files` entry. Composer's autoload maps
// never address these by name, so the derived index is the only route to them —
// and a scan narrowed to `class`/`interface` would lose the other two silently.
interface HelperContract
{
}

trait HelperFallback
{
}

enum HelperMode
{
    case Strict;
}

class HelperRegistry implements HelperContract
{
    use HelperFallback;
}

function helperFormat(string $value): string
{
    return trim($value);
}

function helperNormalize(string $value): string
{
    return strtolower(helperFormat($value));
}
