<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

abstract readonly class Message
{
    /**
     * @param array<array-key, mixed>|null $params
     */
    public function __construct(
        public string $method,
        public ?array $params,
    ) {
    }

    abstract public function isNotification(): bool;
}
