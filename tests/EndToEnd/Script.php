<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

final readonly class Script
{
    /**
     * @param list<StepInterface> $steps
     */
    public function __construct(public array $steps)
    {
    }
}
