<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextDocument::class)]
class TextDocumentTest extends TestCase
{
    public function testCreate(): void
    {
        $doc = new TextDocument(
            uri: 'file:///path/to/file.php',
            languageId: 'php',
            version: 1,
            content: '<?php echo "hello";',
        );

        self::assertSame('file:///path/to/file.php', $doc->uri);
        self::assertSame('php', $doc->languageId);
        self::assertSame(1, $doc->version);
        self::assertSame('<?php echo "hello";', $doc->getContent());
    }

    public function testApplyFullChange(): void
    {
        $doc = new TextDocument(
            uri: 'file:///path/to/file.php',
            languageId: 'php',
            version: 1,
            content: '<?php echo "hello";',
        );

        $updated = $doc->withContent('<?php echo "world";', 2);

        self::assertSame('<?php echo "world";', $updated->getContent());
        self::assertSame(2, $updated->version);
        // Original unchanged
        self::assertSame('<?php echo "hello";', $doc->getContent());
        self::assertSame(1, $doc->version);
    }

    public function testGetLineAtPosition(): void
    {
        $content = "<?php\necho 'line 2';\necho 'line 3';";
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        self::assertSame("<?php", $doc->getLine(0));
        self::assertSame("echo 'line 2';", $doc->getLine(1));
        self::assertSame("echo 'line 3';", $doc->getLine(2));
    }

    public function testOffsetAtPosition(): void
    {
        $content = "<?php\necho 'test';";
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        // Line 0, char 0 = offset 0
        self::assertSame(0, $doc->offsetAt(line: 0, character: 0));
        // Line 0, char 5 = offset 5 (the newline)
        self::assertSame(5, $doc->offsetAt(line: 0, character: 5));
        // Line 1, char 0 = offset 6 (after newline)
        self::assertSame(6, $doc->offsetAt(line: 1, character: 0));
        // Line 1, char 4 = offset 10 (the space in "echo ")
        self::assertSame(10, $doc->offsetAt(line: 1, character: 4));
    }

    public function testPositionAtOffset(): void
    {
        $content = "<?php\necho 'test';";
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        // Offset 0 = line 0, char 0
        self::assertSame(['line' => 0, 'character' => 0], $doc->positionAt(0));
        // Offset 5 = line 0, char 5
        self::assertSame(['line' => 0, 'character' => 5], $doc->positionAt(5));
        // Offset 6 = line 1, char 0
        self::assertSame(['line' => 1, 'character' => 0], $doc->positionAt(6));
        // Offset 10 = line 1, char 4
        self::assertSame(['line' => 1, 'character' => 4], $doc->positionAt(10));
    }

    /**
     * "é" is one UTF-16 code unit but two UTF-8 bytes; "😀" is two UTF-16 units
     * (a surrogate pair) but four bytes. The interior works in byte offsets, so
     * a UTF-16 character column must be converted, not read as a byte column
     * (RFC 1 §4.9, issue #192). Line 1 is `$s = 'é😀';`.
     *
     * @param int<0, max> $character
     * @param int<0, max> $byteOffset
     */
    #[DataProvider('multibyteRoundTripCases')]
    public function testOffsetAndPositionConvertUtf16CharactersToBytes(int $character, int $byteOffset): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, "<?php\n\$s = 'é😀';");

        self::assertSame(
            $byteOffset,
            $doc->offsetAt(line: 1, character: $character),
            'a UTF-16 column must resolve to the byte offset the AST uses, past any multibyte characters',
        );
        self::assertSame(
            ['line' => 1, 'character' => $character],
            $doc->positionAt($byteOffset),
            'a byte offset must resolve back to the UTF-16 column the client sent',
        );
    }

    public function testTextBeforeCursorReadsAsciiToTheColumn(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, "<?php\n\$user->getName();");

        self::assertSame(
            '$user->',
            $doc->textBeforeCursor(line: 1, character: 7),
            'the interior reads text up to the cursor, sliced at the byte column',
        );
    }

    /**
     * A multibyte character before the cursor makes the wire column smaller than
     * the byte column: `é` is one UTF-16 unit but two bytes, so column 7 in
     * `$café->` lands at byte 8. Slicing the raw wire column as a byte length
     * would drop the `>` (RFC 1 §4.9).
     */
    public function testTextBeforeCursorConvertsMultibyteColumnToBytes(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, "<?php\n\$café->x");

        self::assertSame(
            '$café->',
            $doc->textBeforeCursor(line: 1, character: 7),
            'a wire column past a multibyte char must slice at the byte column, not the raw column',
        );
    }

    /**
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{int<0, max>, int<0, max>}>
     */
    public static function multibyteRoundTripCases(): iterable
    {
        // Line 1 begins at byte 6 (after "<?php\n").
        yield 'before the multibyte content' => [6, 12];  // the é
        yield 'after a two-byte BMP char' => [7, 14];      // the 😀, past é
        yield 'after a four-byte astral char' => [9, 18];  // the closing quote, past 😀
    }
}
