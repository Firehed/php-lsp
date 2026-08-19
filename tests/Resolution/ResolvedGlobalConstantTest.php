<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedGlobalConstant::class)]
class ResolvedGlobalConstantTest extends TestCase
{
    use ResolvesFromInfoTestTrait;

    protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol
    {
        return $this->createResolvedConstant(file: $file, line: $line);
    }

    protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol
    {
        return $this->createResolvedConstant(docblock: $docblock);
    }

    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedConstant();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
    }

    public function testGetTypeReturnsNullForUntypedConstant(): void
    {
        $resolved = $this->createResolvedConstant(type: null);

        self::assertNull($resolved->getType());
    }

    public function testGetTypeReturnsTypeForTypedConstant(): void
    {
        $type = new PrimitiveType('int');
        $resolved = $this->createResolvedConstant(type: $type);

        self::assertSame($type, $resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedConstant();

        self::assertSame('const DEBUG', $resolved->format());
    }

    private function createResolvedConstant(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?PrimitiveType $type = null,
    ): ResolvedGlobalConstant {
        $info = new ConstantInfo(
            name: new ConstantName('DEBUG'),
            visibility: Visibility::Public,
            isFinal: true,
            type: $type,
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: null,
        );

        return new ResolvedGlobalConstant($info);
    }
}
