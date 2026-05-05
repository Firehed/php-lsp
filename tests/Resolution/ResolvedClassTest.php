<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedClass::class)]
class ResolvedClassTest extends TestCase
{
    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedClass();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
    }

    public function testGetDefinitionLocation(): void
    {
        $resolved = $this->createResolvedClass();

        $location = $resolved->getDefinitionLocation();

        self::assertNotNull($location);
        self::assertSame('file:///path/to/file.php', $location->uri);
    }

    public function testGetDefinitionLocationReturnsNullWhenFileIsNull(): void
    {
        $resolved = $this->createResolvedClass(file: null);

        self::assertNull($resolved->getDefinitionLocation());
    }

    public function testGetDocumentation(): void
    {
        $resolved = $this->createResolvedClass(docblock: "/**\n * A user entity\n */");

        self::assertSame('A user entity', $resolved->getDocumentation());
    }

    public function testGetDocumentationReturnsNullWhenNoDocblock(): void
    {
        $resolved = $this->createResolvedClass(docblock: null);

        self::assertNull($resolved->getDocumentation());
    }

    public function testGetType(): void
    {
        $resolved = $this->createResolvedClass();

        $type = $resolved->getType();

        self::assertInstanceOf(ClassName::class, $type);
        self::assertSame(\stdClass::class, $type->fqn);
    }

    public function testFormat(): void
    {
        $resolved = $this->createResolvedClass();

        self::assertSame('class stdClass', $resolved->format());
    }

    private function createResolvedClass(
        ?string $file = '/path/to/file.php',
        ?int $line = 10,
        ?string $docblock = null,
    ): ResolvedClass {
        $classInfo = new ClassInfo(
            name: new ClassName(\stdClass::class),
            kind: ClassKind::Class_,
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
            docblock: $docblock,
            file: $file,
            line: $line,
        );

        return new ResolvedClass($classInfo);
    }
}
