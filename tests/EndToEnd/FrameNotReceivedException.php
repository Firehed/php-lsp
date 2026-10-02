<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

use RuntimeException;

final class FrameNotReceivedException extends RuntimeException
{
    public function __construct(string $reason, string $unreadOutput, string $stderr)
    {
        // PHPUnit prints only the message of a failing test's exception, so the
        // server's output goes in it: that is what explains the failure.
        parent::__construct("{$reason}\nUnread output:\n{$unreadOutput}\nStderr:\n{$stderr}");
    }
}
