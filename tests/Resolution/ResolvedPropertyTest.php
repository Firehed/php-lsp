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
    use ResolvesFromInfoTestTrait;

    protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol
    {
        return $this->createResolvedProperty(file: $file, line: $line);
    }

    protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol
    {
        return $this->createResolvedProperty(docblock: $docblock);
    }

    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedProperty();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedMember::class, $resolved);
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
