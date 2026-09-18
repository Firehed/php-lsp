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
}
