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
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: '/** Maximum size */',
            file: '/path/to/file.php',
            line: 5,
            declaringClass: new ClassName(ConstantInfo::class),
        );

        self::assertSame('MAX_SIZE', $constant->name->name);
        self::assertSame(Visibility::Public, $constant->visibility);
        self::assertTrue($constant->isFinal);
        self::assertSame('int', $constant->type?->format());
        self::assertSame('/** Maximum size */', $constant->docblock);
        self::assertSame('/path/to/file.php', $constant->file);
        self::assertSame(5, $constant->line);
        self::assertNotNull($constant->declaringClass, 'class constant has a declaring class');
        self::assertSame(ConstantInfo::class, $constant->declaringClass->fqn);
    }

    public function testFormatSimple(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('FOO'),
            visibility: Visibility::Public,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public const FOO', $constant->format());
    }

    public function testFormatWithType(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: false,
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public const int MAX_SIZE', $constant->format());
    }

    public function testFormatFinal(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('VERSION'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('string'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('public final const string VERSION', $constant->format());
    }

    public function testFormatPrivate(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('INTERNAL'),
            visibility: Visibility::Private,
            isFinal: false,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: new ClassName(self::class),
        );

        self::assertSame('private const INTERNAL', $constant->format());
    }

    public function testFormatGlobalConstant(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('DEBUG'),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        self::assertSame('const DEBUG', $constant->format(), 'global constants omit visibility');
    }

    public function testFormatGlobalConstantWithType(): void
    {
        $constant = new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        self::assertSame('const int MAX_SIZE', $constant->format(), 'global constants show type after const');
    }

    public function testResolvedMemberMetadata(): void
    {
        $constant = $this->makeSubject();

        self::assertSame(MemberKind::Constant, $constant->getMemberKind());
        self::assertSame('MAX_SIZE', $constant->getName()->name);
        self::assertSame(ConstantInfo::class, $constant->getDeclaringClass()->fqn);
        self::assertSame('int', $constant->getType()?->format());
        self::assertSame(Visibility::Public, $constant->getVisibility());
        self::assertTrue($constant->isStatic(), 'a class constant is reached on the class');
    }

    public function testGetDeclaringClassFailsOnGlobalConstant(): void
    {
        $globalConstant = new ConstantInfo(
            name: new ConstantName('DEBUG'),
            visibility: Visibility::Public,
            isFinal: true,
            type: null,
            docblock: null,
            file: null,
            line: null,
            declaringClass: null,
        );

        // With assertions on (dev), assert() throws AssertionError. With them off
        // (prod), assert() is a no-op and PHP's declared return type raises
        // TypeError. Either shape enforces the same invariant.
        try {
            $globalConstant->getDeclaringClass();
            self::fail('getDeclaringClass on a global constant should fail');
        } catch (\AssertionError | \TypeError) {
            // Expected: one of the two, depending on zend.assertions.
            $this->addToAssertionCount(1);
        }
    }

    protected function makeSubject(
        ?string $file = null,
        ?int $line = null,
        ?string $docblock = null,
    ): ConstantInfo {
        return new ConstantInfo(
            name: new ConstantName('MAX_SIZE'),
            visibility: Visibility::Public,
            isFinal: true,
            type: new PrimitiveType('int'),
            docblock: $docblock,
            file: $file,
            line: $line,
            declaringClass: new ClassName(ConstantInfo::class),
        );
    }
}
