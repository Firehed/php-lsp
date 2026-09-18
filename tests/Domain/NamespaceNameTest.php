<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\NamespaceName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespaceName::class)]
final class NamespaceNameTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('provideAncestors')]
    public function testAncestors(string $path, array $expected): void
    {
        self::assertSame(
            $expected,
            (new NamespaceName($path))->ancestors(),
            'Each ancestor maps to the child leading towards the namespace',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function provideAncestors(): iterable
    {
        yield 'global namespace has none' => ['', []];
        yield 'single segment' => ['App', ['' => 'App']];
        yield 'nested' => ['A\B\C', ['' => 'A', 'A' => 'A\B', 'A\B' => 'A\B\C']];
    }

    #[DataProvider('provideEquals')]
    public function testEquals(string $a, string $b, bool $expected): void
    {
        self::assertSame(
            $expected,
            (new NamespaceName($a))->equals(new NamespaceName($b)),
            'Namespace names are case-insensitive',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideEquals(): iterable
    {
        yield 'identical' => ['App\Model', 'App\Model', true];
        yield 'differs only in case' => ['App\Model', 'APP\model', true];
        yield 'global to global' => ['', '', true];
        yield 'unrelated' => ['App', 'Other', false];
        yield 'partial overlap is not equality' => ['App', 'App\Model', false];
    }

    #[DataProvider('provideRelativeTo')]
    public function testRelativeTo(string $namespace, string $ancestor, ?string $expected): void
    {
        self::assertSame(
            $expected,
            (new NamespaceName($namespace))->relativeTo(new NamespaceName($ancestor)),
            'A namespace is only relative to one that strictly contains it',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideRelativeTo(): iterable
    {
        yield 'child of global' => ['App\Model', '', 'App\Model'];
        yield 'child of a namespace' => ['App\Model\User', 'App', 'Model\User'];
        yield 'identical namespaces are not relative' => ['App', 'App', null];
        yield 'case-insensitive' => ['APP\Model', 'app', 'Model'];
        yield 'unrelated' => ['Other\Thing', 'App', null];
        yield 'partial segment is not a prefix' => ['App\Models\Thing', 'App\Model', null];
        yield 'global is not below anything' => ['', 'App', null];
    }

    public function testNormalizeIsTheLookupKeyForAPath(): void
    {
        self::assertSame(
            'app\model',
            (new NamespaceName('App\Model'))->normalize(),
            'namespace paths are case-insensitive whatever kind of symbol they qualify',
        );
    }

    #[DataProvider('provideNames')]
    public function testNamespaceAndShortNameSplitTheName(
        string $fqn,
        string $expectedNamespace,
        string $expectedShortName,
    ): void {
        self::assertSame($expectedNamespace, NamespaceName::namespaceOf($fqn), 'Everything before the last separator');
        self::assertSame($expectedShortName, NamespaceName::shortNameOf($fqn), 'Everything after it');
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideNames(): iterable
    {
        yield 'namespaced' => ['App\Model\User', 'App\Model', 'User'];
        yield 'global' => ['Exception', '', 'Exception'];
    }

    #[DataProvider('provideFirstSegments')]
    public function testFirstSegment(string $name, string $expected): void
    {
        self::assertSame(
            $expected,
            NamespaceName::firstSegment($name),
            'The leading segment is what an import binds',
        );
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string}>
     */
    public static function provideFirstSegments(): iterable
    {
        yield 'qualified' => ['Model\User\Repository', 'Model'];
        yield 'unqualified' => ['User', 'User'];
    }

    public function testJoinSkipsEmptySegments(): void
    {
        self::assertSame(
            'App\User',
            NamespaceName::join('', 'App', '', 'User'),
            'A symbol in the global namespace has no empty leading separator',
        );
    }
}
