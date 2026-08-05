<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Resolution\NameKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NameKind::class)]
final class NameKindTest extends TestCase
{
    /**
     * @return iterable<string, array{NameKind, QualifiedName, string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function normalizations(): iterable
    {
        $namespaced = new QualifiedName('Fixtures\Helpers', 'HelperFormat');
        $global = new QualifiedName('', 'HELPER_LIMIT');

        yield 'class-like' => [NameKind::ClassLike, $namespaced, 'fixtures\helpers\helperformat'];
        yield 'function' => [NameKind::Function_, $namespaced, 'fixtures\helpers\helperformat'];
        // Only the short name of a constant survives normalization: the namespace
        // path is case-insensitive for every kind.
        yield 'constant' => [NameKind::Constant, $namespaced, 'fixtures\helpers\HelperFormat'];
        yield 'global constant' => [NameKind::Constant, $global, 'HELPER_LIMIT'];
        yield 'global function' => [NameKind::Function_, $global, 'helper_limit'];
    }

    #[DataProvider('normalizations')]
    public function testNormalizeAppliesTheKindsCaseRule(
        NameKind $kind,
        QualifiedName $name,
        string $expected,
    ): void {
        self::assertSame($expected, $kind->normalize($name));
    }
}
