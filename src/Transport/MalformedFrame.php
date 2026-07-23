<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Firehed\PhpLsp\Protocol\ResponseError;

/**
 * A frame was received but could not be turned into a message.
 *
 * It carries the JSON-RPC error the sender is to be answered with, decided
 * where the raw bytes are still in hand. Malformed input must never terminate
 * the process (RFC 1 §9).
 */
final readonly class MalformedFrame
{
    public function __construct(
        public ResponseError $error,
    ) {
    }
}
