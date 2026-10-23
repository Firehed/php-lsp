<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

use Fixtures\Inheritance\ParentClass;

final class AnonymousParent
{
    public function make(): object
    {
        return new class extends ParentClass {
            public function call(): void
            {
                parent::parentMethod();
                $byParent = fn (parent $anonymousInherited) => $anonymousInherited;
            }

            public function typed(string $anonymousTyped): void
            {
            }
        };
    }
}
