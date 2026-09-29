<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentManager::class)]
class DocumentManagerTest extends TestCase
{
    public function testOpenAndGet(): void
    {
        $manager = new DocumentManager();

        $manager->open(
            uri: 'file:///test.php',
            languageId: 'php',
            version: 1,
            content: '<?php echo "test";',
        );

        $doc = $manager->get('file:///test.php');

        self::assertInstanceOf(TextDocument::class, $doc);
        self::assertSame('<?php echo "test";', $doc->getContent());
    }

    public function testReadAnswersForOpenDocumentsOnly(): void
    {
        $manager = new DocumentManager();
        $manager->open('file:///test.php', 'php', 1, '<?php');

        self::assertSame(
            $manager->get('file:///test.php'),
            $manager->read('file:///test.php'),
            'an open document is read from its buffer',
        );
        self::assertNull(
            $manager->read('file:///unknown.php'),
            'a document that is not open has no buffer to read',
        );
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $manager = new DocumentManager();

        self::assertNull($manager->get('file:///unknown.php'));
    }

    public function testUpdate(): void
    {
        $manager = new DocumentManager();

        $manager->open('file:///test.php', 'php', 1, '<?php echo "v1";');
        $manager->update('file:///test.php', '<?php echo "v2";', 2);

        $doc = $manager->get('file:///test.php');

        self::assertNotNull($doc);
        self::assertSame('<?php echo "v2";', $doc->getContent());
        self::assertSame(2, $doc->version);
    }

    public function testClose(): void
    {
        $manager = new DocumentManager();

        $manager->open('file:///test.php', 'php', 1, '<?php');
        $manager->close('file:///test.php');

        self::assertNull($manager->get('file:///test.php'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function spellingsOfOnePath(): iterable
    {
        yield 'the spelling it was opened under' => ['file:///tmp/a%7eb%20c.php'];
        yield 'uppercase hex' => ['file:///tmp/a%7Eb%20c.php'];
        yield 'unreserved character left raw' => ['file:///tmp/a~b%20c.php'];
        yield 'bare path' => ['/tmp/a~b c.php'];
    }

    #[DataProvider('spellingsOfOnePath')]
    public function testADocumentIsFoundUnderAnySpellingOfItsPath(string $spelling): void
    {
        $manager = new DocumentManager();
        $manager->open('file:///tmp/a%7eb%20c.php', 'php', 1, '<?php');

        self::assertNotNull(
            $manager->get($spelling),
            'clients and the server percent-encode one path differently',
        );
        self::assertTrue($manager->isOpen($spelling), 'isOpen must agree with get');

        $manager->update($spelling, '<?php // v2', 2);
        self::assertSame(
            '<?php // v2',
            $manager->get('file:///tmp/a%7eb%20c.php')?->getContent(),
            'an update under another spelling must reach the same document',
        );

        $manager->close($spelling);
        self::assertNull(
            $manager->get('file:///tmp/a%7eb%20c.php'),
            'a close under another spelling must close the same document',
        );
    }

    public function testIsOpen(): void
    {
        $manager = new DocumentManager();

        self::assertFalse($manager->isOpen('file:///test.php'));

        $manager->open('file:///test.php', 'php', 1, '<?php');

        self::assertTrue($manager->isOpen('file:///test.php'));

        $manager->close('file:///test.php');

        self::assertFalse($manager->isOpen('file:///test.php'));
    }
}
