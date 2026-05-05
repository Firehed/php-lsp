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
    use ResolvesFromInfoTestTrait;

    protected function createSubjectWithLocation(?string $file, ?int $line): ResolvedSymbol
    {
        return $this->createResolvedClass(file: $file, line: $line);
    }

    protected function createSubjectWithDocblock(?string $docblock): ResolvedSymbol
    {
        return $this->createResolvedClass(docblock: $docblock);
    }

    public function testImplementsInterfaces(): void
    {
        $resolved = $this->createResolvedClass();

        self::assertInstanceOf(ResolvedSymbol::class, $resolved);
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
