<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultClassRepository::class)]
final class DefaultClassRepositoryTest extends TestCase
{
    public function testGetReturnsNullForUnknownClass(): void
    {
        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        self::assertNull($repo->get(new ClassName($this->nonExistentClass())));
    }

    public function testGetReturnsClassFromOpenDocument(): void
    {
        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();

        $classInfo = $this->createClassInfo(DocumentClass::class);

        $repo = new DefaultClassRepository($factory, $locator, $parser);
        $repo->updateDocument('file:///test.php', [$classInfo]);

        $result = $repo->get(new ClassName(DocumentClass::class));

        self::assertSame($classInfo, $result);
    }

    public function testGetResolvesClassFromLocator(): void
    {
        $classInfo = $this->createClassInfo(TestFixture::class);

        $factory = self::createMock(ClassInfoFactory::class);
        $factory->expects(self::once())
            ->method('fromAstNode')
            ->willReturn($classInfo);

        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')
            ->willReturn(__DIR__ . '/TestFixture.php');

        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        $result = $repo->get(new ClassName(TestFixture::class));

        self::assertSame($classInfo, $result);
    }

    public function testGetResolvesBuiltInClassFromReflection(): void
    {
        $classInfo = $this->createClassInfo(\stdClass::class);

        $factory = self::createMock(ClassInfoFactory::class);
        $factory->expects(self::once())
            ->method('fromReflection')
            ->willReturn($classInfo);

        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);

        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        $result = $repo->get(new ClassName(\stdClass::class));

        self::assertSame($classInfo, $result);
    }

    public function testGetCachesResults(): void
    {
        $classInfo = $this->createClassInfo(\stdClass::class);

        $factory = self::createMock(ClassInfoFactory::class);
        $factory->expects(self::once())
            ->method('fromReflection')
            ->willReturn($classInfo);

        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);

        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        $repo->get(new ClassName(\stdClass::class));
        $result = $repo->get(new ClassName(\stdClass::class));

        self::assertSame($classInfo, $result);
    }

    public function testUpdateDocumentInvalidatesCache(): void
    {
        $oldInfo = $this->createClassInfo(DocumentClass::class);
        $newInfo = $this->createClassInfo(DocumentClass::class);

        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);
        $repo->updateDocument('file:///test.php', [$oldInfo]);

        $repo->get(new ClassName(DocumentClass::class));

        $repo->updateDocument('file:///test.php', [$newInfo]);
        $result = $repo->get(new ClassName(DocumentClass::class));

        self::assertSame($newInfo, $result);
    }

    public function testRemoveDocumentClearsClasses(): void
    {
        $fqn = $this->nonExistentClass();
        $classInfo = new ClassInfo(
            name: new ClassName($fqn),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: null,
            interfaces: [],
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: null,
            line: null,
        );

        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);
        $repo->updateDocument('file:///test.php', [$classInfo]);

        $repo->removeDocument('file:///test.php');

        self::assertNull($repo->get(new ClassName($fqn)));
    }

    public function testGetHandlesLeadingBackslash(): void
    {
        $classInfo = $this->createClassInfo(DocumentClass::class);

        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);
        $repo->updateDocument('file:///test.php', [$classInfo]);

        /** @var class-string $withBackslash */
        $withBackslash = '\\' . DocumentClass::class;
        $result = $repo->get(new ClassName($withBackslash));

        self::assertSame($classInfo, $result);
    }

    public function testRemoveDocumentIsIdempotent(): void
    {
        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        $repo->removeDocument('file:///nonexistent.php');

        $this->expectNotToPerformAssertions();
    }

    public function testOpenDocumentTakesPriorityOverCache(): void
    {
        $cachedInfo = $this->createClassInfo(\stdClass::class);
        $documentInfo = $this->createClassInfo(\stdClass::class);

        $factory = self::createMock(ClassInfoFactory::class);
        $factory->expects(self::once())
            ->method('fromReflection')
            ->willReturn($cachedInfo);

        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        $repo->get(new ClassName(\stdClass::class));

        $repo->updateDocument('file:///test.php', [$documentInfo]);
        $result = $repo->get(new ClassName(\stdClass::class));

        self::assertSame($documentInfo, $result);
    }

    /**
     * @param class-string $fqn
     */
    private function createClassInfo(string $fqn): ClassInfo
    {
        return new ClassInfo(
            name: new ClassName($fqn),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: null,
            interfaces: [],
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: null,
            line: null,
        );
    }

    /**
     * @return class-string
     */
    private function nonExistentClass(): string
    {
        // @phpstan-ignore return.type
        return 'NonExistent\\ClassThatDoesNotExist' . random_int(0, PHP_INT_MAX);
    }
}
