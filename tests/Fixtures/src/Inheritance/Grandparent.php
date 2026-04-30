<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

class Grandparent
{
    public const GRANDPARENT_CONST = 'grandparent';

    public string $grandparentProperty = 'grandparent';

    public function grandparentMethod(): void
    {
    }

    protected function protectedGrandparentMethod(): void
    {
    }
}
