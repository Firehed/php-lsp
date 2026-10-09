<?php

declare(strict_types=1);

namespace Fixtures\ParseHealth;

use Fixtures\Attributes\Route;

class ArgumentSeparators
{
    public function target(mixed ...$args): void
    {
    }

    public function calls(string $x): void
    {
        $this->target('a,b',/*|method_1*/ [1, 2],/*|method_2*/ strlen('x, y'));
        self::make(1,/*|static_1*/ fn ($a, $b) => $a);
        new self(/* , */ 1,/*|new_1*/ 2,/*|new_2*/);
        $this?->target("{$x}, y",/*|nullsafe_1*/ 2);
        strlen($x);
    }

    #[Route('/a',/*|attribute_1*/ methods: ['GET', 'POST'])]
    public function routed(): void
    {
    }

    public static function make(mixed ...$args): self
    {
        return new self();
    }
}
