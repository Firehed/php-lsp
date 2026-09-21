<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Tests\Domain\HasSymbolLocationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyInfo::class)]
class PropertyInfoTest extends TestCase
{
    use HasSymbolLocationTestTrait;

    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified(PropertyInfo::class);
        $property = new PropertyInfo(
            name: new PropertyName($owner, 'value'),
            visibility: Visibility::Private,
            isStatic: false,
            isReadonly: true,
            isPromoted: true,
            type: new PrimitiveType('string'),
            docblock: null,
            file: '/path/to/file.php',
            line: 10,
        );

        self::assertSame('value', $property->name->name);
        self::assertSame(Visibility::Private, $property->visibility);
        self::assertFalse($property->isStatic);
        self::assertTrue($property->isReadonly);
        self::assertTrue($property->isPromoted);
        self::assertSame('string', $property->type?->format());
        self::assertNull($property->docblock);
        self::assertSame('/path/to/file.php', $property->file);
        self::assertSame(10, $property->line);
        self::assertSame(PropertyInfo::class, $property->name->owner->qualifiedName->fullyQualifiedName());
    }

    public function testFormatSimple(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'name'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: new PrimitiveType('string'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public string $name', $property->format());
    }

    public function testFormatStatic(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'instance'),
            visibility: Visibility::Private,
            isStatic: true,
            isReadonly: false,
            isPromoted: false,
            type: new PrimitiveType('self'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('private static self $instance', $property->format());
    }

    public function testFormatReadonly(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'id'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: true,
            isPromoted: false,
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('public readonly int $id', $property->format());
    }

    public function testFormatNoType(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'data'),
            visibility: Visibility::Protected,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('protected $data', $property->format());
    }

    public function testFormatAllModifiers(): void
    {
        $property = new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(self::class), 'cache'),
            visibility: Visibility::Private,
            isStatic: true,
            isReadonly: true,
            isPromoted: false,
            type: new PrimitiveType('array'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('private static readonly array $cache', $property->format());
    }

    public function testResolvedMemberMetadata(): void
    {
        $property = $this->makeSubject();

        self::assertSame(MemberKind::Property, $property->getMemberKind());
        self::assertSame('value', $property->getName()->name);
        self::assertSame(PropertyInfo::class, $property->getDeclaringClass()->qualifiedName->fullyQualifiedName());
        self::assertSame('string', $property->getType()?->format());
        self::assertSame(Visibility::Public, $property->getVisibility());
        self::assertFalse($property->isStatic());
    }

    protected function makeSubject(?string $file = null, ?int $line = null, ?string $docblock = null): PropertyInfo
    {
        return new PropertyInfo(
            name: new PropertyName(ClasslikeName::fromFullyQualified(PropertyInfo::class), 'value'),
            visibility: Visibility::Public,
            isStatic: false,
            isReadonly: false,
            isPromoted: false,
            type: new PrimitiveType('string'),
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
