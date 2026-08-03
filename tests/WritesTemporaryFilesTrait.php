<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Provides a real on-disk directory for tests that must exercise the filesystem
 * rather than a fixture: an external change to a file, a file that appears after
 * a cache was populated, or two autoload prefixes claiming one name.
 *
 * A fixture cannot express any of those — it is a fixed tree checked in alongside
 * the tests — so these tests write and mutate their own.
 */
trait WritesTemporaryFilesTrait
{
    private function createTemporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertNotFalse($path, 'a temporary path must be obtainable');
        unlink($path);
        self::assertTrue(mkdir($path), 'the temporary directory must be created');

        return $path;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($contents as $entry) {
            assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }

    /**
     * Writes a PHP file, creating the directories leading to it. The `<?php` tag
     * is supplied so callers state only the declaration under test.
     */
    private function writePhpFile(string $path, string $body): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, recursive: true), "the directory for {$path} must be created");
        }

        self::assertNotFalse(file_put_contents($path, "<?php\n{$body}\n"), "{$path} must be writable");
    }
}
