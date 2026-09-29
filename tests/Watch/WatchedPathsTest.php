<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Watch;

use Firehed\PhpLsp\Watch\WatchedPaths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WatchedPaths::class)]
final class WatchedPathsTest extends TestCase
{
    public function testMergingKeepsEveryPathOnce(): void
    {
        $merged = new WatchedPaths(roots: ['/a', '/b'], files: ['/x.php'])
            ->with(new WatchedPaths(roots: ['/b', '/c'], files: ['/x.php', '/y.php']));

        self::assertSame(['/a', '/b', '/c'], $merged->roots, 'a root two holders name is watched once');
        self::assertSame(['/x.php', '/y.php'], $merged->files, 'a file two holders name is watched once');
    }
}
