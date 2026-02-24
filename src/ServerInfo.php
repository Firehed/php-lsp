<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

final readonly class ServerInfo
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
    }

    /**
     * @return array{name: string, version: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
        ];
    }
}
