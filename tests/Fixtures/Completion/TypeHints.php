<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Domain\User;
use Fixtures\Domain\Entity;

class TypeHints
{
    public function parameterHint(/*|param_type*/ $value): void
    {
    }

    public function parameterWithPrefix(Us/*|param_prefix*/ $user): void
    {
    }

    public function returnHint(): /*|return_type*/
    {
    }

    public function nullableParam(?/*|nullable_param*/ $value): void
    {
    }

    public function unionType(string|/*|union_second*/ $value): void
    {
    }

    public function propertyType(): void
    {
    }
}

class PropertyTypes
{
    public /*|property_type*/ $untyped;
    public Us/*|property_prefix*/ $user;
    public ?/*|nullable_property*/ $nullable;
}
