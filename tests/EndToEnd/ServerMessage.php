<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use stdClass;

/**
 * One decoded frame from the server: a response when `method` is null,
 * otherwise a request (with an id) or a notification (without).
 *
 * JSON objects are `stdClass` so that `{}` and `[]` stay distinguishable.
 */
final readonly class ServerMessage
{
    /**
     * @param stdClass|list<mixed>|string|int|float|bool|null $result
     * @param stdClass|list<mixed>|null $params
     */
    private function __construct(
        public int|string|null $id,
        public ?string $method,
        public stdClass|array|null $params,
        public stdClass|array|string|int|float|bool|null $result,
        public ?stdClass $error,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $body = json_decode($json, flags: JSON_THROW_ON_ERROR);
        assert($body instanceof stdClass);

        $id = $body->id ?? null;
        $method = $body->method ?? null;
        $params = $body->params ?? null;
        $result = $body->result ?? null;
        $error = $body->error ?? null;
        assert($id === null || is_int($id) || is_string($id));
        assert($method === null || is_string($method));
        assert($params === null || $params instanceof stdClass || self::isList($params));
        assert($result === null || is_scalar($result) || $result instanceof stdClass || self::isList($result));
        assert($error === null || $error instanceof stdClass);

        return new self($id, $method, $params, $result, $error);
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
