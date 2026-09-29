<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

interface BeforeMessageInterface
{
    /**
     * Called once for each message the server is about to handle.
     */
    public function beforeMessage(): void;
}
