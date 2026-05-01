<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class Config
{
    public function __construct(
        private readonly array $data = [],
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
