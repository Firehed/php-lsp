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
    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedConstant();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
        self::assertInstanceOf(ResolvedMember::class, $resolved);
    }

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createResolvedConstant();

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createResolvedConstant(docblock: "/**\n * Max retries\n */");

        self::assertSame('Max retries', $resolved->getDocumentation());
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
            file: '/path/to/file.php',
            line: 10,
            declaringClass: $declaringClass ?? new ClassName(\stdClass::class),
        );

        return new ResolvedConstant($constantInfo);
    }
}
