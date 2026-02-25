<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

final readonly class NotificationMessage extends Message
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        assert(is_string($data['method']));
        $params = $data['params'] ?? null;
        assert($params === null || is_array($params));

        return new self(
            method: $data['method'],
            params: $params,
        );
    }

    public function isNotification(): bool
    {
        return true;
    }
}
