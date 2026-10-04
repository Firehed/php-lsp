<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

final readonly class Open implements StepInterface
{
    /**
     * @param string $file Path relative to the project root.
     */
    public function __construct(private string $file)
    {
    }

    public function run(Session $session): void
    {
        $session->open($this->file);
    }
}
