<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use Firehed\PhpLsp\Tests\Domain\HasSymbolLocationTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantInfo::class)]
#[CoversClass(PrimitiveType::class)]
class ConstantInfoTest extends TestCase
{
    use HasSymbolLocationTestTrait;

    public function testConstruction(): void
    {
        $constant = new ConstantInfo(
            name: ConstantName::fromFullyQualified('APP\\DEBUG'),
            type: new PrimitiveType('bool'),
            docblock: '/** Debug flag */',
            file: '/path/to/file.php',
            line: 5,
        );

        self::assertSame('DEBUG', $constant->name->qualifiedName->shortName);
        self::assertSame('bool', $constant->type?->format());
        self::assertSame('/** Debug flag */', $constant->docblock);
        self::assertSame('/path/to/file.php', $constant->file);
        self::assertSame(5, $constant->line);
    }

    public function testGetTypeReportsTheDeclaredType(): void
    {
        $typed = new ConstantInfo(
            name: ConstantName::fromFullyQualified('MAX_SIZE'),
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
        );
        $untyped = new ConstantInfo(
            name: ConstantName::fromFullyQualified('DEBUG'),
            type: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('int', $typed->getType()?->format(), 'a typed constant returns its declared type');
        self::assertNull($untyped->getType(), 'an untyped constant returns null');
    }

    public function testSymbolKindIsConstant(): void
    {
        $constant = new ConstantInfo(
            name: ConstantName::fromFullyQualified('DEBUG'),
            type: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame(
            SymbolKind::Constant,
            $constant->symbolKind(),
            'a free constant reports the Constant LSP kind',
        );
    }

    public function testFormatGlobalConstant(): void
    {
        $constant = new ConstantInfo(
            name: ConstantName::fromFullyQualified('DEBUG'),
            type: null,
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('const DEBUG', $constant->format(), 'free constants omit visibility');
    }

    public function testFormatGlobalConstantWithType(): void
    {
        $constant = new ConstantInfo(
            name: ConstantName::fromFullyQualified('MAX_SIZE'),
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
        );

        self::assertSame('const int MAX_SIZE', $constant->format(), 'free constants render type after const');
    }

    protected function makeSubject(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ConstantInfo {
        return new ConstantInfo(
            name: ConstantName::fromFullyQualified('APP\\LIMIT'),
            type: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
        );
    }
}
