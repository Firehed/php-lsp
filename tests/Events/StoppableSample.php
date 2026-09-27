<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Events;

use Psr\EventDispatcher\StoppableEventInterface;

final class StoppableSample implements StoppableEventInterface
{
    private bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
