<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

use Firehed\PhpLsp\Tests\EndToEnd\Marker\MarkerInterface;
use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;

final readonly class Type implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It must be open.
     * @param string $typed A short fragment, as a person would type it.
     */
    public function __construct(
        private string $file,
        private MarkerInterface $at,
        private string $typed,
    ) {
    }

    public function run(Session $session): void
    {
        $session->type($this->file, $this->at, $this->typed);
    }
}
