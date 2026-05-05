<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedProperty::class)]
class ResolvedPropertyTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedProperty();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedMember::class, $resolved);
    }

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createResolvedProperty(file: '/path/to/file.php', line: 10);

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
        self::assertSame(9, $location->startLine);
    }

    public function testGetDefinitionLocationReturnsNullWhenFileIsNull(): void
    {
        $resolved = $this->createResolvedProperty(file: null, line: 10);

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createResolvedProperty(docblock: "/**\n * The name property\n */");

        self::assertSame('The name property', $resolved->getDocumentation());
    }

    public function testGetDocumentationReturnsNullWhenNoDocblock(): void
    {
        $resolved = $this->createResolvedProperty(docblock: null);

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetType(): void
    {
        $type = new PrimitiveType('string');
        $resolved = $this->createResolvedProperty(type: $type);

        self::assertSame($type, $resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedProperty();

        self::assertSame('public string $name', $resolved->format());
    }

    public function testGetDeclaringClass(): void
    {
        $className = new ClassName(\stdClass::class);
        $resolved = $this->createResolvedProperty(declaringClass: $className);

        self::assertSame($className, $resolved->getDeclaringClass());
    }

    public function testGetVisibility(): void
    {
        $resolved = $this->createResolvedProperty(visibility: Visibility::Private);

        self::assertSame(Visibility::Private, $resolved->getVisibility());
    }

    public function testIsStatic(): void
    {
        $resolved = $this->createResolvedProperty(isStatic: true);

        self::assertTrue($resolved->isStatic());
    }

    private function createResolvedProperty(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?PrimitiveType $type = null,
        ?ClassName $declaringClass = null,
        Visibility $visibility = Visibility::Public,
        bool $isStatic = false,
    ): ResolvedProperty {
        $propertyInfo = new PropertyInfo(
            name: new PropertyName('name'),
            visibility: $visibility,
            isStatic: $isStatic,
            isReadonly: false,
            isPromoted: false,
            type: $type ?? new PrimitiveType('string'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: $declaringClass ?? new ClassName(\stdClass::class),
        );

        return new ResolvedProperty($propertyInfo);
    }
}
