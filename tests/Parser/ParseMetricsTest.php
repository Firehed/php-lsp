<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\ParseMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParseMetrics::class)]
class ParseMetricsTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $metrics = new ParseMetrics();

        self::assertSame(0, $metrics->getParseCount(), 'a fresh collector has observed no parses');
        self::assertSame(0, $metrics->getTotalParseTimeNs(), 'a fresh collector has accumulated no time');
    }

    public function testRecordAccumulates(): void
    {
        $metrics = new ParseMetrics();

        $metrics->record(1_000);
        $metrics->record(2_500);

        self::assertSame(2, $metrics->getParseCount(), 'each recorded parse increments the count');
        self::assertSame(3_500, $metrics->getTotalParseTimeNs(), 'recorded durations sum');
    }
}
