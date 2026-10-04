<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

/**
 * Replaces or creates a file on disk with a prepared variant, as a checkout
 * or a save in another program would. The server is not told.
 */
final readonly class Copy implements StepInterface
{
    /**
     * @param string $from Path relative to the project root.
     * @param string $to Path relative to the project root.
     */
    public function __construct(
        private string $from,
        private string $to,
    ) {
    }

    public function run(Session $session): void
    {
        $session->copy($this->from, $this->to);
    }
}
