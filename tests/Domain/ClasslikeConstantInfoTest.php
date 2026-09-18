<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Tests\Domain\HasSymbolLocationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClasslikeConstantInfo::class)]
#[CoversClass(PrimitiveType::class)]
class ClasslikeConstantInfoTest extends TestCase
{
    use HasSymbolLocationTestTrait;

    public function testConstruction(): void
    {
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: '/** Maximum size */',
            file: '/path/to/file.php',
            line: 5,
            declaringClass: ClasslikeName::fromFullyQualified(ClasslikeConstantInfo::class),
        );

        self::assertSame('MAX_SIZE', $constant->name->name);
        self::assertSame(Visibility::Public, $constant->visibility);
        self::assertTrue($constant->isFinal);
        self::assertSame('int', $constant->type?->format());
        self::assertSame('/** Maximum size */', $constant->docblock);
        self::assertSame('/path/to/file.php', $constant->file);
        self::assertSame(5, $constant->line);
        self::assertSame(ClasslikeConstantInfo::class, $constant->declaringClass->fullyQualifiedName());
    }

    public function testFormatSimple(): void
    {
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('FOO'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: ClasslikeName::fromFullyQualified(self::class),
        );

        self::assertSame('public const FOO', $constant->format());
    }

    public function testFormatWithType(): void
    {
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: false,
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: ClasslikeName::fromFullyQualified(self::class),
        );

        self::assertSame('public const int MAX_SIZE', $constant->format());
    }

    public function testFormatFinal(): void
    {
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('VERSION'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('string'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: ClasslikeName::fromFullyQualified(self::class),
        );

        self::assertSame('public final const string VERSION', $constant->format());
    }

    public function testFormatPrivate(): void
    {
        $constant = new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('INTERNAL'),
            visibility: Visibility::Private,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: ClasslikeName::fromFullyQualified(self::class),
        );

        self::assertSame('private const INTERNAL', $constant->format());
    }

    public function testSymbolKindIsConstant(): void
    {
        $constant = $this->makeSubject();

        self::assertSame(
            SymbolKind::Constant,
            $constant->symbolKind(),
            'a class-owned constant reports the Constant LSP kind',
        );
    }

    public function testResolvedMemberMetadata(): void
    {
        $constant = $this->makeSubject();

        self::assertSame(MemberKind::Constant, $constant->getMemberKind());
        self::assertSame('MAX_SIZE', $constant->getName()->name);
        self::assertSame(ClasslikeConstantInfo::class, $constant->getDeclaringClass()->fullyQualifiedName());
        self::assertSame('int', $constant->getType()?->format());
        self::assertSame(Visibility::Public, $constant->getVisibility());
        self::assertTrue($constant->isStatic(), 'a class constant is reached on the class');
    }

    protected function makeSubject(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ClasslikeConstantInfo {
        return new ClasslikeConstantInfo(
            name: new ClasslikeConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: ClasslikeName::fromFullyQualified(ClasslikeConstantInfo::class),
        );
    }
}
