<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\MemberFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberFilter::class)]
class MemberFilterTest extends TestCase
{
    /**
     * @return array<string, array{MemberFilter, bool, bool}>
     * @codeCoverageIgnore
     */
    public static function matchesProvider(): array
    {
        return [
            'Instance matches non-static' => [MemberFilter::Instance, false, true],
            'Instance rejects static' => [MemberFilter::Instance, true, false],
            'Static matches static' => [MemberFilter::Static, true, true],
            'Static rejects non-static' => [MemberFilter::Static, false, false],
            'Both matches static' => [MemberFilter::Both, true, true],
            'Both matches non-static' => [MemberFilter::Both, false, true],
        ];
    }

    #[DataProvider('matchesProvider')]
    public function testMatches(MemberFilter $filter, bool $isStatic, bool $expected): void
    {
        self::assertSame($expected, $filter->matches($isStatic));
    }
}
