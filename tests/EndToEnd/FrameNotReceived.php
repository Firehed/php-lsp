<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use RuntimeException;

final class FrameNotReceived extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly string $unreadOutput,
        public readonly string $stderr,
    ) {
        parent::__construct("{$reason}\nUnread output:\n{$unreadOutput}\nStderr:\n{$stderr}");
    }
}
