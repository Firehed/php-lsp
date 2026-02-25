<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
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
