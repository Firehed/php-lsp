<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Utility\NamespacePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamespacePath::class)]
class NamespacePathTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('provideAncestors')]
    public function testAncestors(string $namespace, array $expected): void
    {
        self::assertSame(
            $expected,
            NamespacePath::ancestors($namespace),
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

    #[DataProvider('provideRelativeTo')]
    public function testRelativeTo(string $namespace, string $ancestor, ?string $expected): void
    {
        self::assertSame(
            $expected,
            NamespacePath::relativeTo($namespace, $ancestor),
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

    #[DataProvider('provideNames')]
    public function testNamespaceAndShortNameSplitTheName(
        string $fqn,
        string $expectedNamespace,
        string $expectedShortName,
    ): void {
        self::assertSame($expectedNamespace, NamespacePath::namespaceOf($fqn), 'Everything before the last separator');
        self::assertSame($expectedShortName, NamespacePath::shortNameOf($fqn), 'Everything after it');
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
            NamespacePath::firstSegment($name),
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
            NamespacePath::join('', 'App', '', 'User'),
            'A symbol in the global namespace has no empty leading separator',
        );
    }
}
