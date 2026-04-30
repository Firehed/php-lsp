<?php

declare(strict_types=1);

namespace Fixtures\Enum;

/**
 * Task priority levels.
 */
enum Priority: int
{
    case Low = 1;
    case Medium = 5;
    case High = 10;
    case Critical = 100;

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => 'Low Priority',
            self::Medium => 'Medium Priority',
            self::High => 'High Priority',
            self::Critical => 'Critical',
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 100 => self::Critical,
            $score >= 10 => self::High,
            $score >= 5 => self::Medium,
            default => self::Low,
        };
    }
}
