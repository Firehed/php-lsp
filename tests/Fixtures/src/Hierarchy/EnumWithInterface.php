<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

enum EnumWithInterface: string implements BaseInterface
{
    case First = 'first';
    case Second = 'second';

    public function baseMethod(): string
    {
        return $this->value;
    }
}
