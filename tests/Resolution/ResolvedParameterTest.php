<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedParameter::class)]
class ResolvedParameterTest extends TestCase
{
    public function testImplementsResolvedSymbol(): void
    {
        $resolved = $this->createResolvedParameter();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
    }

    public function testGetDefinitionLocationAlwaysReturnsNull(): void
    {
        $resolved = $this->createResolvedParameter();

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentationAlwaysReturnsNull(): void
    {
        $resolved = $this->createResolvedParameter();

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetType(): void
    {
        $type = new PrimitiveType('string');
        $resolved = $this->createResolvedParameter(type: $type);

        self::assertSame($type, $resolved->getType());
    }

    public function testGetTypeReturnsNullWhenUntyped(): void
    {
        $paramInfo = new ParameterInfo(
            name: 'data',
            type: null,
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );
        $resolved = new ResolvedParameter($paramInfo);

        self::assertNull($resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedParameter();

        self::assertSame('string $name', $resolved->format());
    }

    private function createResolvedParameter(?PrimitiveType $type = null): ResolvedParameter
    {
        $paramInfo = new ParameterInfo(
            name: 'name',
            type: $type ?? new PrimitiveType('string'),
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );

        return new ResolvedParameter($paramInfo);
    }
}
