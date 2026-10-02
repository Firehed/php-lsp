<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

final readonly class Ask implements StepInterface
{
    /**
     * @param string $file Path relative to the project root. It need not be open.
     * @param string $symbolMarker A `//hover:name` marker in the file.
     */
    public function __construct(
        private Feature $feature,
        private string $file,
        private string $symbolMarker,
    ) {
    }

    public function run(Session $session): void
    {
        $session->ask($this->feature, $this->file, $this->symbolMarker);
    }
}
