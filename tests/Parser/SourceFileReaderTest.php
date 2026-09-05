<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SourceFileReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceFileReader::class)]
final class SourceFileReaderTest extends TestCase
{
    public function testReadReturnsTextDocumentWithFileContents(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/src/Domain/User.php';

        $doc = (new SourceFileReader())->read($path);

        self::assertInstanceOf(TextDocument::class, $doc);
        self::assertSame(
            (string) file_get_contents($path),
            $doc->getContent(),
            'the returned document must carry the file contents verbatim',
        );
    }

    /**
     * @return iterable<string, array{string}>
     * @codeCoverageIgnore
     */
    public static function unreadablePaths(): iterable
    {
        yield 'no such file' => [__DIR__ . '/does-not-exist.php'];
        yield 'a directory' => [__DIR__];
    }

    #[DataProvider('unreadablePaths')]
    public function testReadReturnsNullForAPathItCannotRead(string $path): void
    {
        self::assertNull(
            (new SourceFileReader())->read($path),
            'a path that cannot be read as a file degrades to null rather than warning or throwing',
        );
    }
}
