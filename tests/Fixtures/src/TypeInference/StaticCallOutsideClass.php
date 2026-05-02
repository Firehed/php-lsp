<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

function callSelfOutsideClass(): void
{
    $x = self::method();
}

function callParentOutsideClass(): void
{
    $x = parent::method();
}
