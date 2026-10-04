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
     * @param stdClass $body The whole frame, as received.
     * @param stdClass|list<mixed>|string|int|float|bool|null $result
     */
    public function __construct(
        public stdClass $body,
        public int|string|null $id,
        public ?string $method,
        public stdClass|array|string|int|float|bool|null $result,
        public ?stdClass $error,
    ) {
    }
}
