<?php

declare(strict_types=1);

namespace Fixtures\SignatureHelp;

use Fixtures\Domain\User;

class Triggers extends User
{
    public static function staticMethod(int $a, int $b): int
    {
        return $a + $b;
    }

    public function triggerThis(): void
    {
        $this->setName(/*|this_call*/"name");
    }

    public function triggerSelf(): int
    {
        return self::staticMethod(/*|self_call*/1, 2);
    }

    public function triggerNullsafeProperty(): void
    {
        $this->manager?->setName(/*|nullsafe_property*/"name");
    }
}
