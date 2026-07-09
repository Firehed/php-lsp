<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\PrefixMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrefixMatcher::class)]
class PrefixMatcherTest extends TestCase
{
    #[DataProvider('provideMatches')]
    public function testMatches(string $name, string $prefix, bool $expected): void
    {
        self::assertSame($expected, PrefixMatcher::matches($name, $prefix));
    }

    /**
     * @codeCoverageIgnore
     * @return iterable<string, array{string, string, bool}>
     */
    public static function provideMatches(): iterable
    {
        yield 'empty prefix matches anything' => ['getName', '', true];
        yield 'case-insensitive prefix' => ['getName', 'GET', true];
        yield 'exact prefix' => ['getName', 'getN', true];
        yield 'non-matching prefix' => ['getName', 'set', false];
        yield 'prefix longer than name' => ['id', 'identifier', false];
    }
}
