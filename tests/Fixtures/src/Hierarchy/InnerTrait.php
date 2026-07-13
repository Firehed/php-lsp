<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

trait InnerTrait
{
    public const INNER_CONST = 'inner';

    public string $innerProperty = 'inner';

    public function innerMethod(): string
    {
        return 'inner';
    }
}
