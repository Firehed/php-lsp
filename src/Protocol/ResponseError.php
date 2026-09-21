<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use JsonSerializable;

final readonly class ResponseError implements JsonSerializable
{
    public string $message;

    public function __construct(
        public ErrorCode $code,
        ?string $message = null,
        public mixed $data = null,
    ) {
        $this->message = $message ?? $code->defaultMessage();
    }

    /**
     * @return array{code: int, message: string, data?: mixed}
     */
    public function jsonSerialize(): array
    {
        $result = [
            'code' => $this->code->value,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $result['data'] = $this->data;
        }
        return $result;
    }
}
