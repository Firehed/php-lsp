<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

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
