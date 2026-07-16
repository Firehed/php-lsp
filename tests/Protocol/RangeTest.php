<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\Range;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Range::class)]
class RangeTest extends TestCase
{
    public function testOnLineIsASingleLineSpan(): void
    {
        $range = Range::onLine(4, 8, 10);

        self::assertSame(
            [
                'start' => ['line' => 4, 'character' => 8],
                'end' => ['line' => 4, 'character' => 10],
            ],
            $range->toArray(),
            'A single-line span shares its line between start and end',
        );
    }

    public function testAMultiLineRangeSerializes(): void
    {
        $range = new Range(2, 5, 7, 1);

        self::assertSame(
            [
                'start' => ['line' => 2, 'character' => 5],
                'end' => ['line' => 7, 'character' => 1],
            ],
            $range->toArray(),
            'Start and end may sit on different lines',
        );
    }
}
