<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\CompositeDocumentSource;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\SourceFileReader;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeDocumentSource::class)]
final class CompositeDocumentSourceTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string ON_DISK = 'src/Domain/User.php';

    private const string IN_BUFFER = 'src/Domain/Entity.php';

    private DocumentManager $buffers;

    private CompositeDocumentSource $source;

    protected function setUp(): void
    {
        $this->buffers = new DocumentManager();
        $this->source = new CompositeDocumentSource($this->buffers, new SourceFileReader());
    }

    public function testAnOpenDocumentIsReadFromItsBuffer(): void
    {
        $uri = FileUri::fromPath($this->fixturePath(self::ON_DISK));
        $this->buffers->open($uri, 'php', 1, $this->loadFixture(self::IN_BUFFER));

        self::assertSame(
            $this->loadFixture(self::IN_BUFFER),
            $this->source->read($uri)?->getContent(),
            'while a document is open its content is the client\'s, not the disk\'s',
        );
    }

    public function testAnOpenDocumentIsFoundByItsPath(): void
    {
        $path = $this->fixturePath(self::ON_DISK);
        $this->buffers->open(FileUri::fromPath($path), 'php', 1, $this->loadFixture(self::IN_BUFFER));

        self::assertSame(
            $this->loadFixture(self::IN_BUFFER),
            $this->source->read($path)?->getContent(),
            'a caller holding a path must reach the same buffer as one holding the URI',
        );
    }

    public function testAClosedDocumentIsReadFromDisk(): void
    {
        $uri = FileUri::fromPath($this->fixturePath(self::ON_DISK));
        $this->buffers->open($uri, 'php', 1, $this->loadFixture(self::IN_BUFFER));
        $this->buffers->close($uri);

        self::assertSame(
            $this->loadFixture(self::ON_DISK),
            $this->source->read($uri)?->getContent(),
            'once closed, the document\'s content is whatever the URI points to',
        );
    }

    public function testADocumentThatIsNeitherOpenNorOnDiskIsNull(): void
    {
        self::assertNull(
            $this->source->read('file:///no/such/file.php'),
            'no member can answer for a document that exists nowhere',
        );
    }
}
