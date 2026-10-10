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
        self::make(max(1,/*|inner_1*/ 2),/*|outer_1*/ function ($a, $b) {
            return [$a, $b];
        },/*|outer_2*/ match ($x) {
            'a', 'b' => 1,
            default => 2,
        },/*|outer_3*/ "${x}, y",/*|outer_4*/ #[Route('/x',/*|attribute_2*/ methods: ['GET'])] fn ($c, $d) => $c);
        new class (1,/*|anonymous_1*/ 2) {
            public function inner(int $a, int $b): void
            {
            }
        };
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
