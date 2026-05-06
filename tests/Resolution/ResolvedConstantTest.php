<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedConstant::class)]
class ResolvedConstantTest extends TestCase
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
        self::assertInstanceOf(ResolvedMember::class, $resolved);
    }

    public function testGetType(): void
    {
        $type = new PrimitiveType('int');
        $resolved = $this->createResolvedConstant(type: $type);

        self::assertSame($type, $resolved->getType());
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedConstant();

        self::assertSame('public const int MAX_RETRIES', $resolved->format());
    }

    public function testGetDeclaringClass(): void
    {
        $className = new ClassName(\stdClass::class);
        $resolved = $this->createResolvedConstant(declaringClass: $className);

        self::assertSame($className, $resolved->getDeclaringClass());
    }

    public function testGetName(): void
    {
        $resolved = $this->createResolvedConstant();

        $name = $resolved->getName();

        self::assertInstanceOf(ConstantName::class, $name);
        self::assertSame('MAX_RETRIES', $name->name);
    }

    public function testGetVisibility(): void
    {
        $resolved = $this->createResolvedConstant(visibility: Visibility::Private);

        self::assertSame(Visibility::Private, $resolved->getVisibility());
    }

    public function testIsStaticAlwaysTrue(): void
    {
        $resolved = $this->createResolvedConstant();

        self::assertTrue($resolved->isStatic());
    }

    private function createResolvedConstant(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
        ?PrimitiveType $type = null,
        ?ClassName $declaringClass = null,
        Visibility $visibility = Visibility::Public,
    ): ResolvedConstant {
        $constantInfo = new ConstantInfo(
            name: new ConstantName('MAX_RETRIES'),
            visibility: $visibility,
            isFinal: false,
            type: $type ?? new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: $declaringClass ?? new ClassName(\stdClass::class),
        );

        return new ResolvedConstant($constantInfo);
    }
}
