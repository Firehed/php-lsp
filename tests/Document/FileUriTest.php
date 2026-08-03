<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\FileUri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileUri::class)]
final class FileUriTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function uriAndPath(): iterable
    {
        yield 'plain' => ['file:///app/src/User.php', '/app/src/User.php'];
        yield 'encoded space' => ['file:///app/my%20project/User.php', '/app/my project/User.php'];
        yield 'encoded hash' => ['file:///app/a%23b/User.php', '/app/a#b/User.php'];
    }

    #[DataProvider('uriAndPath')]
    public function testToPathStripsTheSchemeAndDecodes(string $uri, string $path): void
    {
        self::assertSame(
            $path,
            FileUri::toPath($uri),
            'a URI percent-encodes reserved characters; a filesystem path does not',
        );
    }

    #[DataProvider('uriAndPath')]
    public function testFromPathAddsTheSchemeAndEncodes(string $uri, string $path): void
    {
        self::assertSame($uri, FileUri::fromPath($path), 'the conversion must round-trip');
    }

    public function testToPathLeavesANonFileUriUnchanged(): void
    {
        // Callers hold paths and URIs from several sources; a bare path passed here
        // is returned as-is rather than mangled.
        self::assertSame('/already/a/path.php', FileUri::toPath('/already/a/path.php'));
    }

    public function testFromPathDoesNotEncodeDirectorySeparators(): void
    {
        self::assertSame(
            'file:///a/b/c.php',
            FileUri::fromPath('/a/b/c.php'),
            'separators are structure, not data, and must survive encoding',
        );
    }
}
