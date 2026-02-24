<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use JsonSerializable;

final readonly class ResponseMessage implements JsonSerializable
{
    private function __construct(
        private int|string|null $id,
        private mixed $result,
        private ?ResponseError $error,
    ) {
    }

    public static function success(int|string $id, mixed $result): self
    {
        return new self($id, $result, null);
    }

    public static function error(int|string|null $id, ResponseError $error): self
    {
        return new self($id, null, $error);
    }

    /**
     * @return array{jsonrpc: string, id: int|string|null, result?: mixed, error?: ResponseError}
     */
    public function jsonSerialize(): array
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $this->id,
        ];

        if ($this->error !== null) {
            $response['error'] = $this->error;
        } else {
            $response['result'] = $this->result;
        }

        return $response;
    }
}
