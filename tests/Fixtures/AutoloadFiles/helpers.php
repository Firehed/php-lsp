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

function helperFormat(string $value): string
{
    return trim($value);
}

function helperNormalize(string $value): string
{
    return strtolower(helperFormat($value));
}
