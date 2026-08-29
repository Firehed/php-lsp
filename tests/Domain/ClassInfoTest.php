<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassInfo::class)]
class ClassInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $class = new ClassInfo(
            name: new ClassName(ClassInfo::class),
            kind: ClassKind::Class_,
            isAbstract: false,
            isFinal: true,
            isReadonly: true,
            isAttribute: false,
            parent: new ClassName(TestCase::class),
            interfaces: [new ClassName(\Stringable::class)],
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: '/** My class */',
            file: '/path/to/file.php',
            line: 3,
        );

        self::assertSame(ClassInfo::class, $class->name->fqn);
        self::assertSame(ClassKind::Class_, $class->kind);
        self::assertFalse($class->isAbstract);
        self::assertTrue($class->isFinal);
        self::assertTrue($class->isReadonly);
        self::assertSame(TestCase::class, $class->parent?->fqn);
        self::assertCount(1, $class->interfaces);
        self::assertSame(\Stringable::class, $class->interfaces[0]->fqn);
        self::assertSame([], $class->traits);
        self::assertSame([], $class->methods);
        self::assertSame([], $class->properties);
        self::assertSame([], $class->constants);
        self::assertSame([], $class->enumCases);
        self::assertSame('/** My class */', $class->docblock);
        self::assertSame('/path/to/file.php', $class->file);
        self::assertSame(3, $class->line);
    }

    public function testConstructionWithNullParent(): void
    {
        $class = new ClassInfo(
            name: new ClassName(\Stringable::class),
            kind: ClassKind::Interface_,
            isAbstract: false,
            isFinal: false,
            isReadonly: false,
            isAttribute: false,
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

        self::assertNull($class->parent);
        self::assertNull($class->docblock);
        self::assertNull($class->file);
        self::assertNull($class->line);
    }

    #[DataProvider('kindPredicateProvider')]
    public function testKindPredicates(
        ClassKind $kind,
        bool $isClass,
        bool $isInterface,
        bool $isTrait,
    ): void {
        $info = $this->createClassInfo(name: \stdClass::class, kind: $kind);
        self::assertSame($isClass, $info->isClass(), 'isClass');
        self::assertSame($isInterface, $info->isInterface(), 'isInterface');
        self::assertSame($isTrait, $info->isTrait(), 'isTrait');
    }

    /** @return iterable<string, array{ClassKind, bool, bool, bool}> */
    public static function kindPredicateProvider(): iterable
    {
        //                                    isClass  isIface  isTrait
        yield 'class'     => [ClassKind::Class_,     true,  false, false];
        yield 'interface' => [ClassKind::Interface_,  false, true,  false];
        yield 'trait'     => [ClassKind::Trait_,      false, false, true];
        yield 'enum'      => [ClassKind::Enum_,       false, false, false];
    }

    public function testFormatSimpleClass(): void
    {
        $class = $this->createClassInfo(
            name: \stdClass::class,
            kind: ClassKind::Class_,
        );

        self::assertSame('class stdClass', $class->format());
    }

    public function testFormatFinalClass(): void
    {
        $class = $this->createClassInfo(
            name: \stdClass::class,
            kind: ClassKind::Class_,
            isFinal: true,
        );

        self::assertSame('final class stdClass', $class->format());
    }

    public function testFormatAbstractClass(): void
    {
        $class = $this->createClassInfo(
            name: \stdClass::class,
            kind: ClassKind::Class_,
            isAbstract: true,
        );

        self::assertSame('abstract class stdClass', $class->format());
    }

    public function testFormatReadonlyClass(): void
    {
        $class = $this->createClassInfo(
            name: \stdClass::class,
            kind: ClassKind::Class_,
            isReadonly: true,
        );

        self::assertSame('readonly class stdClass', $class->format());
    }

    public function testFormatClassWithParent(): void
    {
        $class = $this->createClassInfo(
            name: \Exception::class,
            kind: ClassKind::Class_,
            parent: new ClassName(\Error::class),
        );

        self::assertSame('class Exception extends Error', $class->format());
    }

    public function testFormatClassWithInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: \stdClass::class,
            kind: ClassKind::Class_,
            interfaces: [
                new ClassName(\JsonSerializable::class),
                new ClassName(\Stringable::class),
            ],
        );

        self::assertSame('class stdClass implements JsonSerializable, Stringable', $class->format());
    }

    public function testFormatClassWithParentAndInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: \Exception::class,
            kind: ClassKind::Class_,
            isFinal: true,
            parent: new ClassName(\Error::class),
            interfaces: [new ClassName(\JsonSerializable::class)],
        );

        self::assertSame('final class Exception extends Error implements JsonSerializable', $class->format());
    }

    public function testFormatInterface(): void
    {
        $class = $this->createClassInfo(
            name: \Stringable::class,
            kind: ClassKind::Interface_,
        );

        self::assertSame('interface Stringable', $class->format());
    }

    public function testFormatInterfaceExtendingInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: \Stringable::class,
            kind: ClassKind::Interface_,
            interfaces: [
                new ClassName(\JsonSerializable::class),
                new ClassName(\Countable::class),
            ],
        );

        self::assertSame('interface Stringable extends JsonSerializable, Countable', $class->format());
    }

    public function testFormatTrait(): void
    {
        $class = $this->createClassInfo(
            name: \Generator::class,
            kind: ClassKind::Trait_,
        );

        self::assertSame('trait Generator', $class->format());
    }

    public function testFormatEnum(): void
    {
        $class = $this->createClassInfo(
            name: ClassKind::class,
            kind: ClassKind::Enum_,
        );

        self::assertSame('enum ClassKind', $class->format());
    }

    public function testGetTypeReturnsClassName(): void
    {
        $class = $this->createClassInfo(ClassInfo::class, ClassKind::Class_);

        self::assertSame(ClassInfo::class, $class->getType()->fqn);
    }

    public function testGetDefinitionLocation(): void
    {
        $class = $this->createClassInfo(ClassInfo::class, ClassKind::Class_, file: '/path/to/file.php', line: 3);

        $location = $class->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(2, $location->startLine);
    }

    public function testGetDefinitionLocationNullWhenFileNull(): void
    {
        self::assertNull($this->createClassInfo(ClassInfo::class, ClassKind::Class_)->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $class = $this->createClassInfo(ClassInfo::class, ClassKind::Class_, docblock: "/**\n * A prose line\n */");

        self::assertSame('A prose line', $class->getDocumentation());
    }

    public function testGetDocumentationNullWhenNoDocblock(): void
    {
        self::assertNull($this->createClassInfo(ClassInfo::class, ClassKind::Class_)->getDocumentation());
    }

    /**
     * @param class-string $name
     * @param list<ClassName> $interfaces
     */
    private function createClassInfo(
        string $name,
        ClassKind $kind,
        bool $isAbstract = false,
        bool $isFinal = false,
        bool $isReadonly = false,
        ?ClassName $parent = null,
        array $interfaces = [],
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ClassInfo {
        return new ClassInfo(
            name: new ClassName($name),
            kind: $kind,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            isReadonly: $isReadonly,
            isAttribute: false,
            parent: $parent,
            interfaces: $interfaces,
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
