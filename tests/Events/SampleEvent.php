<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Events;

use Firehed\PhpLsp\Events\EventInterface;

final readonly class SampleEvent implements EventInterface
{
    public string $type;

    public function __construct()
    {
        $this->type = self::class;
    }
}
