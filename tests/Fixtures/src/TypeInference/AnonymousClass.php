<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class AnonymousClass
{
    public function getAnonymous(): object
    {
        return new class {
            public function createSelf(): self
            {
                return new self();
            }
        };
    }
}
