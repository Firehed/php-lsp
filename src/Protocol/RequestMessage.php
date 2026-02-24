<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

final readonly class RequestMessage extends Message
{
    /**
     * @param array<array-key, mixed>|null $params
     */
    public function __construct(
        public int|string $id,
        string $method,
        ?array $params,
    ) {
        parent::__construct($method, $params);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        assert(is_int($data['id']) || is_string($data['id']));
        assert(is_string($data['method']));
        $params = $data['params'] ?? null;
        assert($params === null || is_array($params));

        return new self(
            id: $data['id'],
            method: $data['method'],
            params: $params,
        );
    }

    public function isNotification(): bool
    {
        return false;
    }
}
