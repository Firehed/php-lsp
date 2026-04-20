<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

enum VisibilityFilter
{
    case All;
    case PublicOnly;
    case PublicProtected;

    public function getMethodFlags(): int
    {
        return match ($this) {
            self::All => \ReflectionMethod::IS_PUBLIC
                | \ReflectionMethod::IS_PROTECTED
                | \ReflectionMethod::IS_PRIVATE,
            self::PublicOnly => \ReflectionMethod::IS_PUBLIC,
            self::PublicProtected => \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED,
        };
    }

    public function getPropertyFlags(): int
    {
        return match ($this) {
            self::All => \ReflectionProperty::IS_PUBLIC
                | \ReflectionProperty::IS_PROTECTED
                | \ReflectionProperty::IS_PRIVATE,
            self::PublicOnly => \ReflectionProperty::IS_PUBLIC,
            self::PublicProtected => \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED,
        };
    }

    public function getConstantFlags(): int
    {
        return match ($this) {
            self::All => \ReflectionClassConstant::IS_PUBLIC
                | \ReflectionClassConstant::IS_PROTECTED
                | \ReflectionClassConstant::IS_PRIVATE,
            self::PublicOnly => \ReflectionClassConstant::IS_PUBLIC,
            self::PublicProtected => \ReflectionClassConstant::IS_PUBLIC | \ReflectionClassConstant::IS_PROTECTED,
        };
    }
}
