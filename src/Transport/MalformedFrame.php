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
    /**
     * @param int|string|null $id The id to answer at. Null only when none could
     *        be recovered from the frame, per JSON-RPC 2.0 §5: an id that *was*
     *        detected must be echoed, or the client's pending request is never
     *        correlated to this error and never resolves.
     */
    public function __construct(
        public ResponseError $error,
        public int|string|null $id = null,
    ) {
    }
}
