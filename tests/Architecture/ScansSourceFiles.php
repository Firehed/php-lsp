<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

/**
 * File walking shared by the architecture tests that read every file under
 * `src/` rather than running as a PHPStan rule.
 */
trait ScansSourceFiles
{
    /**
     * @return iterable<string>
     */
    private static function sourceFiles(): iterable
    {
        $root = self::root() . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
                yield $entry->getPathname();
            }
        }
    }

    private static function relativePath(string $file): string
    {
        $root = self::root() . '/';
        if (str_starts_with($file, $root)) {
            return substr($file, strlen($root));
        }
        // @codeCoverageIgnoreStart
        return $file;
        // @codeCoverageIgnoreEnd
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
