<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Deletes a file on disk, as another program would. The server is not told.
 */
final readonly class Delete implements StepInterface
{
    /**
     * @param string $file Path relative to the project root.
     */
    public function __construct(private string $file)
    {
    }

    public function run(Session $session): void
    {
        $session->delete($this->file);
    }
}
