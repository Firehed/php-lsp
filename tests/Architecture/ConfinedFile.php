<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

/**
 * Where a confinement rule does not apply: tests (except the rule-test data,
 * which exists to violate), and the rule's named homes, matched as exact
 * repo-relative paths so that a suffix or a `tests/` segment elsewhere in a
 * path cannot exempt a source file.
 */
final class ConfinedFile
{
    private const string DATA_PREFIX = 'tests/Architecture/data/';
    private const string TESTS_PREFIX = 'tests/';

    /**
     * @param list<string> $allowedFiles repo-relative paths
     */
    public static function isExempt(string $file, array $allowedFiles): bool
    {
        $relative = self::relativePath($file);
        if (str_starts_with($relative, self::TESTS_PREFIX) && !str_starts_with($relative, self::DATA_PREFIX)) {
            return true;
        }

        return in_array($relative, $allowedFiles, true);
    }

    private static function relativePath(string $file): string
    {
        $resolved = realpath($file);
        if ($resolved !== false) {
            $file = $resolved;
        }
        $root = dirname(__DIR__, 2) . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }
}
