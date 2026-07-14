<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

interface BaseInterface
{
    public const BASE_CONST = 'base';

    public function baseMethod(): string;
}
