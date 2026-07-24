<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

/**
 * The stream closed cleanly with no frame pending.
 *
 * This is a distinct read outcome rather than a null Message because RFC 1 §9
 * requires a message lacking a required header to be distinguishable from end
 * of stream: one means stop serving, the other means answer with an error and
 * keep serving.
 */
final readonly class EndOfStream
{
}
