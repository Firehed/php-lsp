<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

/**
 * Counts parses and accumulates the time they take, so that reparse cost can be
 * observed rather than assumed.
 *
 * Callers that need a scoped measurement take the difference between two reads;
 * the collector is never reset, so no caller can clear another's baseline.
 */
final class ParseMetrics
{
    private int $parseCount = 0;

    private int $totalParseTimeNs = 0;

    public function getParseCount(): int
    {
        return $this->parseCount;
    }

    public function getTotalParseTimeNs(): int
    {
        return $this->totalParseTimeNs;
    }

    public function record(int $durationNs): void
    {
        $this->parseCount++;
        $this->totalParseTimeNs += $durationNs;
    }
}
