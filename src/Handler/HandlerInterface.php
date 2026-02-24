<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Protocol\Message;

interface HandlerInterface
{
    public function supports(string $method): bool;

    public function handle(Message $message): mixed;
}
