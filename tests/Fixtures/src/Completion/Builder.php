<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class Builder
{
    private array $data = [];

    public static function create(): self
    {
        return new self();
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function build(): array
    {
        return $this->data;
    }
}
