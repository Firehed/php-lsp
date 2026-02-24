<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use JsonSerializable;

final readonly class ResponseError implements JsonSerializable
{
    public function __construct(
        public int $code,
        public string $message,
        public mixed $data = null,
    ) {
    }

    public static function parseError(?string $data = null): self
    {
        return new self(-32700, 'Parse error', $data);
    }

    public static function invalidRequest(?string $data = null): self
    {
        return new self(-32600, 'Invalid Request', $data);
    }

    public static function methodNotFound(?string $method = null): self
    {
        $message = $method !== null
            ? "Method not found: $method"
            : 'Method not found';
        return new self(-32601, $message);
    }

    public static function invalidParams(?string $data = null): self
    {
        return new self(-32602, 'Invalid params', $data);
    }

    public static function internalError(?string $data = null): self
    {
        return new self(-32603, 'Internal error', $data);
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $result['data'] = $this->data;
        }
        return $result;
    }
}
