<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testFormatSimpleClass(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\User',
            kind: ClassKind::Class_,
        );

        self::assertSame('class User', $class->format());
    }

    public function testFormatFinalClass(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\User',
            kind: ClassKind::Class_,
            isFinal: true,
        );

        self::assertSame('final class User', $class->format());
    }

    public function testFormatAbstractClass(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\Entity',
            kind: ClassKind::Class_,
            isAbstract: true,
        );

        self::assertSame('abstract class Entity', $class->format());
    }

    public function testFormatReadonlyClass(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\Value',
            kind: ClassKind::Class_,
            isReadonly: true,
        );

        self::assertSame('readonly class Value', $class->format());
    }

    public function testFormatClassWithParent(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\User',
            kind: ClassKind::Class_,
            parent: new ClassName('App\\Entity'),
        );

        self::assertSame('class User extends Entity', $class->format());
    }

    public function testFormatClassWithInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\User',
            kind: ClassKind::Class_,
            interfaces: [
                new ClassName('JsonSerializable'),
                new ClassName('Stringable'),
            ],
        );

        self::assertSame('class User implements JsonSerializable, Stringable', $class->format());
    }

    public function testFormatClassWithParentAndInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\User',
            kind: ClassKind::Class_,
            isFinal: true,
            parent: new ClassName('App\\Entity'),
            interfaces: [new ClassName('JsonSerializable')],
        );

        self::assertSame('final class User extends Entity implements JsonSerializable', $class->format());
    }

    public function testFormatInterface(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\UserInterface',
            kind: ClassKind::Interface_,
        );

        self::assertSame('interface UserInterface', $class->format());
    }

    public function testFormatInterfaceExtendingInterfaces(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\UserInterface',
            kind: ClassKind::Interface_,
            interfaces: [
                new ClassName('JsonSerializable'),
                new ClassName('Stringable'),
            ],
        );

        self::assertSame('interface UserInterface extends JsonSerializable, Stringable', $class->format());
    }

    public function testFormatTrait(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\LoggerTrait',
            kind: ClassKind::Trait_,
        );

        self::assertSame('trait LoggerTrait', $class->format());
    }

    public function testFormatEnum(): void
    {
        $class = $this->createClassInfo(
            name: 'App\\Status',
            kind: ClassKind::Enum_,
        );

        self::assertSame('enum Status', $class->format());
    }

    /**
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
    ): ClassInfo {
        return new ClassInfo(
            name: new ClassName($name),
            kind: $kind,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            isReadonly: $isReadonly,
            parent: $parent,
            interfaces: $interfaces,
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
}
