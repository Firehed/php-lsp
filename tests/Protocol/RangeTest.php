<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Protocol;

use Firehed\PhpLsp\Protocol\PositionEncoding;
use Firehed\PhpLsp\Protocol\Range;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Range::class)]
class RangeTest extends TestCase
{
    public function testForPrefixSpansTheTypedPrefixEndingAtTheWireColumn(): void
    {
        $range = Range::forPrefix(0, 5, 'Log', PositionEncoding::Utf16);

        self::assertSame(
            [
                'start' => ['line' => 0, 'character' => 2],
                'end' => ['line' => 0, 'character' => 5],
            ],
            $range->toArray(),
            'The span starts a prefix-width back from the wire column and ends at it',
        );
    }

    public function testForPrefixMeasuresThePrefixInCodeUnitsNotBytes(): void
    {
        // "café" is four codepoints but five UTF-8 bytes; the range is measured in
        // the negotiated encoding's code units, so it starts four columns back, not
        // five (RFC 1 §4.9).
        $range = Range::forPrefix(0, 4, 'café', PositionEncoding::Utf16);

        self::assertSame(
            ['line' => 0, 'character' => 0],
            $range->toArray()['start'],
            'A multibyte prefix must not push the range start past the line by counting bytes',
        );
    }

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
