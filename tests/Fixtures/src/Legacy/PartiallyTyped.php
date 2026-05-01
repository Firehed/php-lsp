<?php

declare(strict_types=1);

namespace Fixtures\Legacy;

/**
 * Mixed typing styles - native types where easy, docblocks for complex types.
 */
class PartiallyTyped
{
    private string $name;

    /** @var array<string, int> */
    private array $scores;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->scores = [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param array<string, int> $scores
     */
    public function setScores(array $scores): void
    {
        $this->scores = $scores;
    }

    /**
     * @return array<string, int>
     */
    public function getScores(): array
    {
        return $this->scores;
    }

    public function addScore(string $key, int $value): void
    {
        $this->scores[$key] = $value;
    }

    /**
     * @return int|null
     */
    public function getScore(string $key)
    {
        return $this->scores[$key] ?? null;
    }
}
