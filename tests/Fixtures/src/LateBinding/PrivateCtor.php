<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

final class PrivateCtor
{
    private function __construct(
        public string $label,
    ) {
    }

    public static function make(string $label): self
    {
        return new self(/*|sig_new_self_inside*/$label);
    }
}
