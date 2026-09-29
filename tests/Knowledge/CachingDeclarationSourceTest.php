<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Knowledge\CachingDeclarationSource;
use Firehed\PhpLsp\Knowledge\DeclarationSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachingDeclarationSource::class)]
final class CachingDeclarationSourceTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LoadsFixturesTrait;

    private const string PATH = '/project/src/User.php';

    private DeclarationSourceInterface&MockObject $inner;

    private CachingDeclarationSource $source;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(DeclarationSourceInterface::class);
        $this->source = new CachingDeclarationSource($this->inner, CacheFactory::inMemory());
    }

    public function testTheSameDocumentIsDerivedOnce(): void
    {
        $declared = [self::declaredClass('Fixtures\Domain\User', file: self::PATH)];
        $this->inner->expects($this->once())->method('declarationsIn')->willReturn($declared);

        $text = $this->loadFixture('src/Domain/User.php');

        self::assertSame(
            $declared,
            $this->source->declarationsIn(new TextDocument(self::PATH, 'php', 0, $text)),
            'a first read answers from the inner source',
        );
        self::assertSame(
            $declared,
            $this->source->declarationsIn(new TextDocument(FileUri::fromPath(self::PATH), 'php', 7, $text)),
            'the same text at the same file is the same document, however it is named or versioned',
        );
    }

    public function testChangedTextIsDerivedAgain(): void
    {
        $before = [self::declaredClass('Fixtures\Domain\User', file: self::PATH)];
        $after = [self::declaredClass('Fixtures\Domain\Entity', file: self::PATH)];
        $this->inner->expects($this->exactly(2))
            ->method('declarationsIn')
            ->willReturnOnConsecutiveCalls($before, $after);

        $this->source->declarationsIn(
            new TextDocument(self::PATH, 'php', 1, $this->loadFixture('src/Domain/User.php')),
        );

        self::assertSame(
            $after,
            $this->source->declarationsIn(
                new TextDocument(self::PATH, 'php', 2, $this->loadFixture('src/Domain/Entity.php')),
            ),
            'an answer derived from other text says nothing about this text',
        );
    }

    public function testTheSameTextInAnotherFileIsDerivedAgain(): void
    {
        $here = [self::declaredClass('Fixtures\Domain\User', file: self::PATH)];
        $there = [self::declaredClass('Fixtures\Domain\User', file: '/project/copy/User.php')];
        $this->inner->expects($this->exactly(2))
            ->method('declarationsIn')
            ->willReturnOnConsecutiveCalls($here, $there);

        $text = $this->loadFixture('src/Domain/User.php');
        $this->source->declarationsIn(new TextDocument(self::PATH, 'php', 0, $text));

        self::assertSame(
            $there,
            $this->source->declarationsIn(new TextDocument('/project/copy/User.php', 'php', 0, $text)),
            'a declaration names the file it is in, so one file\'s answer is not another\'s',
        );
    }

    public function testAnEmptyAnswerIsRemembered(): void
    {
        $this->inner->expects($this->once())->method('declarationsIn')->willReturn([]);

        $document = new TextDocument(self::PATH, 'php', 0, $this->loadFixture('src/Domain/User.php'));
        $this->source->declarationsIn($document);

        self::assertSame(
            [],
            $this->source->declarationsIn($document),
            'a document that declares nothing is an answer, not a miss',
        );
    }
}
