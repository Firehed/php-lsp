<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConstantName::class)]
final class ConstantNameTest extends TestCase
{
    public function testCarriesItsKindIntrinsically(): void
    {
        $name = ConstantName::fromFullyQualified('Fixtures\Helpers\HELPER_LIMIT');

        self::assertSame(
            NameKind::Constant,
            $name->kind(),
            'a constant name must say what it names without being told',
        );
    }

    public function testWrapsTheKindNeutralName(): void
    {
        $name = ConstantName::fromFullyQualified('\Fixtures\Helpers\HELPER_LIMIT');

        self::assertEquals(
            new QualifiedName('Fixtures\Helpers', 'HELPER_LIMIT'),
            $name->qualifiedName,
            'the wrapped name should be split and normalized by QualifiedName',
        );
        self::assertSame('Fixtures\Helpers\HELPER_LIMIT', $name->fullyQualifiedName());
    }

    public function testGlobalConstantHasNoNamespace(): void
    {
        $name = ConstantName::fromFullyQualified('PHP_VERSION');

        self::assertSame('', $name->qualifiedName->namespace);
        self::assertSame('PHP_VERSION', $name->fullyQualifiedName());
    }
}
