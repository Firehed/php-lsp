<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FunctionName::class)]
final class FunctionNameTest extends TestCase
{
    public function testCarriesItsKindIntrinsically(): void
    {
        $name = FunctionName::fromFullyQualified('Fixtures\Helpers\helperFormat');

        self::assertSame(
            NameKind::Function_,
            $name->kind(),
            'a function name must say what it names without being told',
        );
    }

    public function testWrapsTheKindNeutralName(): void
    {
        $name = FunctionName::fromFullyQualified('\Fixtures\Helpers\helperFormat');

        self::assertEquals(
            new QualifiedName('Fixtures\Helpers', 'helperFormat'),
            $name->qualifiedName,
            'the wrapped name should be split and normalized by QualifiedName',
        );
        self::assertSame('Fixtures\Helpers\helperFormat', $name->fullyQualifiedName());
    }

    public function testGlobalFunctionHasNoNamespace(): void
    {
        $name = FunctionName::fromFullyQualified('str_contains');

        self::assertSame('', $name->qualifiedName->namespace);
        self::assertSame('str_contains', $name->fullyQualifiedName());
    }
}
