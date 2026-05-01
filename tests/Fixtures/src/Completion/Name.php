<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class Name
{
    public function __construct(
        private readonly string $value,
    ) {
    }

    public function toUpper(): string
    {
        return strtoupper($this->value);
    }

    public function toLower(): string
    {
        return strtolower($this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
