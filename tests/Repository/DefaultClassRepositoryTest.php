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

    public function testIsSubclassOfDirectParent(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfoWithParent('App\\Child', 'App\\Parent'),
            $this->createClassInfo('App\\Parent'),
        ]);

        /** @var class-string $child */
        $child = 'App\\Child';
        /** @var class-string $parent */
        $parent = 'App\\Parent';

        self::assertTrue($repo->isSubclassOf(new ClassName($child), new ClassName($parent)));
    }

    public function testIsSubclassOfTransitiveParent(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfoWithParent('App\\Grandchild', 'App\\Child'),
            $this->createClassInfoWithParent('App\\Child', 'App\\Parent'),
            $this->createClassInfo('App\\Parent'),
        ]);

        /** @var class-string $grandchild */
        $grandchild = 'App\\Grandchild';
        /** @var class-string $parent */
        $parent = 'App\\Parent';

        self::assertTrue($repo->isSubclassOf(new ClassName($grandchild), new ClassName($parent)));
    }

    public function testIsSubclassOfImplementedInterface(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfoWithInterfaces('App\\MyClass', ['App\\MyInterface']),
            $this->createClassInfo('App\\MyInterface', ClassKind::Interface_),
        ]);

        /** @var class-string $class */
        $class = 'App\\MyClass';
        /** @var class-string $interface */
        $interface = 'App\\MyInterface';

        self::assertTrue($repo->isSubclassOf(new ClassName($class), new ClassName($interface)));
    }

    public function testIsSubclassOfInheritedInterface(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfoWithInterfaces('App\\MyClass', ['App\\ChildInterface']),
            $this->createClassInfoWithInterfaces('App\\ChildInterface', ['App\\ParentInterface'], ClassKind::Interface_),
            $this->createClassInfo('App\\ParentInterface', ClassKind::Interface_),
        ]);

        /** @var class-string $class */
        $class = 'App\\MyClass';
        /** @var class-string $interface */
        $interface = 'App\\ParentInterface';

        self::assertTrue($repo->isSubclassOf(new ClassName($class), new ClassName($interface)));
    }

    public function testIsSubclassOfReturnsFalseForUnrelatedClasses(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfo('App\\ClassA'),
            $this->createClassInfo('App\\ClassB'),
        ]);

        /** @var class-string $a */
        $a = 'App\\ClassA';
        /** @var class-string $b */
        $b = 'App\\ClassB';

        self::assertFalse($repo->isSubclassOf(new ClassName($a), new ClassName($b)));
    }

    public function testIsSubclassOfReturnsFalseForSameClass(): void
    {
        $repo = $this->createRepoWithClasses([
            $this->createClassInfo('App\\MyClass'),
        ]);

        /** @var class-string $class */
        $class = 'App\\MyClass';

        self::assertFalse($repo->isSubclassOf(new ClassName($class), new ClassName($class)));
    }

    public function testIsSubclassOfReturnsFalseForUnknownClass(): void
    {
        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $locator->method('locate')->willReturn(null);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);

        /** @var class-string $unknown */
        $unknown = 'App\\Unknown';
        /** @var class-string $other */
        $other = 'App\\Other';

        self::assertFalse($repo->isSubclassOf(new ClassName($unknown), new ClassName($other)));
    }

    /**
     * @param list<ClassInfo> $classes
     */
    private function createRepoWithClasses(array $classes): DefaultClassRepository
    {
        $factory = self::createStub(ClassInfoFactory::class);
        $locator = self::createStub(ClassLocator::class);
        $parser = new ParserService();

        $repo = new DefaultClassRepository($factory, $locator, $parser);
        $repo->updateDocument('file:///test.php', $classes);

        return $repo;
    }

    /**
     * @param class-string $fqn
     * @param class-string $parentFqn
     */
    private function createClassInfoWithParent(string $fqn, string $parentFqn): ClassInfo
    {
        return new ClassInfo(
            name: new ClassName($fqn),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: new ClassName($parentFqn),
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
     * @param class-string $fqn
     * @param list<class-string> $interfaceFqns
     */
    private function createClassInfoWithInterfaces(
        string $fqn,
        array $interfaceFqns,
        ClassKind $kind = ClassKind::Class_,
    ): ClassInfo {
        return new ClassInfo(
            name: new ClassName($fqn),
            kind: $kind,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            parent: null,
            interfaces: array_map(fn($i) => new ClassName($i), $interfaceFqns),
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
     * @param class-string $fqn
     */
    private function createClassInfo(string $fqn, ClassKind $kind = ClassKind::Class_): ClassInfo
    {
        return new ClassInfo(
            name: new ClassName($fqn),
            kind: $kind,
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
