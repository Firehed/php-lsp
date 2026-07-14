<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

interface MiddleInterface extends BaseInterface
{
    public const MIDDLE_CONST = 'middle';

    public function middleMethod(): string;
}
