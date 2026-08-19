<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalConstantName::class)]
final class GlobalConstantNameTest extends TestCase
{
    public function testCarriesItsKindIntrinsically(): void
    {
        $name = GlobalConstantName::fromFullyQualified('Fixtures\Helpers\HELPER_LIMIT');

        self::assertSame(
            NameKind::Constant,
            $name->kind(),
            'a constant name must say what it names without being told',
        );
    }

    public function testWrapsTheKindNeutralName(): void
    {
        $name = GlobalConstantName::fromFullyQualified('\Fixtures\Helpers\HELPER_LIMIT');

        self::assertEquals(
            new QualifiedName('Fixtures\Helpers', 'HELPER_LIMIT'),
            $name->qualifiedName,
            'the wrapped name should be split and normalized by QualifiedName',
        );
        self::assertSame('Fixtures\Helpers\HELPER_LIMIT', $name->fullyQualifiedName());
    }

    public function testGlobalConstantHasNoNamespace(): void
    {
        $name = GlobalConstantName::fromFullyQualified('PHP_VERSION');

        self::assertSame('', $name->qualifiedName->namespace);
        self::assertSame('PHP_VERSION', $name->fullyQualifiedName());
    }
}
