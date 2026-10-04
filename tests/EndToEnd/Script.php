<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

final readonly class Script
{
    /**
     * @param string $project The project the server runs in, as a path from
     *        the repository root. Steps name files relative to it.
     * @param list<StepInterface> $steps
     */
    public function __construct(
        public string $project,
        public array $steps,
    ) {
    }
}
