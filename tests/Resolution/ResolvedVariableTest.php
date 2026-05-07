<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedVariable::class)]
class ResolvedVariableTest extends TestCase
{
    public function testImplementsResolvedSymbol(): void
    {
        $resolved = new ResolvedVariable('name', new PrimitiveType('string'));

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
    }

    public function testGetName(): void
    {
        $resolved = new ResolvedVariable('myVar', new PrimitiveType('string'));

        self::assertSame('myVar', $resolved->getName());
    }

    public function testGetDefinitionLocationAlwaysReturnsNull(): void
    {
        $resolved = new ResolvedVariable('name', new PrimitiveType('string'));

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentationAlwaysReturnsNull(): void
    {
        $resolved = new ResolvedVariable('name', new PrimitiveType('string'));

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetType(): void
    {
        $type = new PrimitiveType('string');
        $resolved = new ResolvedVariable('name', $type);

        self::assertSame($type, $resolved->getType());
    }

    public function testGetTypeReturnsNullWhenUntyped(): void
    {
        $resolved = new ResolvedVariable('data', null);

        self::assertNull($resolved->getType());
    }

    public function testFormatWithType(): void
    {
        $resolved = new ResolvedVariable('name', new PrimitiveType('string'));

        self::assertSame('string $name', $resolved->format());
    }

    public function testFormatWithoutType(): void
    {
        $resolved = new ResolvedVariable('data', null);

        self::assertSame('$data', $resolved->format());
    }
}
