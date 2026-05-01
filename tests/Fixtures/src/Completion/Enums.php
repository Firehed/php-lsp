<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Enum\Priority;
use Fixtures\Enum\Status;

enum StringColor: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}

class EnumUsage
{
    public function triggerUnitEnum(): void
    {
        Status::/*|unit_enum_empty*/
    }

    public function triggerUnitEnumPrefix(): void
    {
        Status::A/*|unit_enum_prefix*/
    }

    public function triggerBuiltinMethod(): void
    {
        Status::c/*|unit_enum_builtin*/
    }

    public function triggerBackedIntEmpty(): void
    {
        Priority::/*|backed_int_empty*/
    }

    public function triggerBackedIntPrefix(): void
    {
        Priority::f/*|backed_int_prefix*/
    }

    public function triggerBackedStringEmpty(): void
    {
        StringColor::/*|backed_string_empty*/
    }
}
