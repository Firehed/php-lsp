<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

final readonly class Close implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It must be open.
     */
    public function __construct(private string $file)
    {
    }

    public function run(Session $session): void
    {
        $session->close($this->file);
    }
}
