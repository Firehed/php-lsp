<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

/**
 * Replaces an open document's text with a prepared variant, as an edit in the
 * editor would. The disk is not changed.
 */
final readonly class Replace implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It must be open.
     * @param string $with Path relative to the project root.
     */
    public function __construct(
        private string $file,
        private string $with,
    ) {
    }

    public function run(Session $session): void
    {
        $session->replace($this->file, $this->with);
    }
}
