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

function thisInFunction(): void
{
    $x = $this;
}

function unknownClassParameter(UnknownClass $obj): void
{
    $result = $obj->name;
}

function methodCallOnUnresolvedType(): void
{
    $result = $unknown->someMethod();
}
