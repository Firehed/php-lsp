<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

class Base
{
    public const string NAME = 'base';

    public function copyInstance(): static
    {
        return $this;
    }

    public static function makeInstance(): static
    {
        return new static();
    }

    public function selfMethod(): self
    {
        return $this;
    }
}
