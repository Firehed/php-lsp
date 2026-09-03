<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * `$this` typing goes through {@see \Firehed\PhpLsp\Resolution\EnclosingClassResolver}
 * (build-manifest step-31): {@see \Firehed\PhpLsp\Resolution\ExpressionResolver}
 * reads the enclosing class from the shared helper, so no file may stash a type
 * on an AST node through a `resolvedType` attribute side-channel. A future
 * `setAttribute('resolvedType', ...)` would fork chain typing and $this typing
 * onto their own paths again; this test fails when one appears.
 */
final class ResolvedTypeAttributeTest extends TestCase
{
    public function testNoSrcFileWritesResolvedTypeAttribute(): void
    {
        $violations = [];
        foreach (self::sourceFiles() as $file) {
            $content = file_get_contents($file);
            self::assertIsString($content, "unable to read {$file}");

            if (str_contains($content, "setAttribute('resolvedType'")) {
                $violations[] = self::relativePath($file);
            }
        }

        self::assertSame(
            [],
            $violations,
            'route $this typing through EnclosingClassResolver instead of a resolvedType attribute',
        );
    }

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
