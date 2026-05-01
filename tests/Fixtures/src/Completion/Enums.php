<?php

declare(strict_types=1);

namespace Fixtures\Completion;

enum UnitStatus
{
    case Active;
    case Inactive;
    case Pending;
}

enum IntPriority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}

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
        UnitStatus::/*|unit_enum_empty*/
    }

    public function triggerUnitEnumPrefix(): void
    {
        UnitStatus::A/*|unit_enum_prefix*/
    }

    public function triggerBuiltinMethod(): void
    {
        UnitStatus::c/*|unit_enum_builtin*/
    }

    public function triggerBackedIntEmpty(): void
    {
        IntPriority::/*|backed_int_empty*/
    }

    public function triggerBackedIntPrefix(): void
    {
        IntPriority::f/*|backed_int_prefix*/
    }

    public function triggerBackedStringEmpty(): void
    {
        StringColor::/*|backed_string_empty*/
    }
}
