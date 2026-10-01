<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use RuntimeException;

final class FrameNotReceived extends RuntimeException
{
    public function __construct(string $reason, string $unreadOutput, string $stderr)
    {
        parent::__construct(sprintf(
            "%s\nUnread output: %s\nStderr: %s",
            $reason,
            json_encode($unreadOutput, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode($stderr, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ));
    }
}
