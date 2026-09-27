<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Events;

use Firehed\PhpLsp\Events\EventInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class StoppableSample implements EventInterface, StoppableEventInterface
{
    private bool $stopped = false;

    public readonly string $type;

    public function __construct()
    {
        $this->type = self::class;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }
}
