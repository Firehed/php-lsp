<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

final readonly class Ask implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     */
    public function __construct(
        private Feature $feature,
        private string $file,
        private MarkerInterface $at,
    ) {
    }

    public function run(Session $session): void
    {
        $session->ask($this->feature, $this->file, $this->at);
    }
}
