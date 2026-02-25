<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
