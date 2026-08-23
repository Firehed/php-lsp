<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

/**
 * A consumer reading a file through the language rather than through a
 * function, which no disallowed-calls entry can see.
 */
final class IncludesAFile
{
    public function requireIt(string $file): mixed
    {
        return require $file;
    }

    public function requireItOnce(string $file): mixed
    {
        return require_once $file;
    }

    public function includeIt(string $file): mixed
    {
        return include $file;
    }

    public function includeItOnce(string $file): mixed
    {
        return include_once $file;
    }
}
