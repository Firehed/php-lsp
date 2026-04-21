<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Utility\PropertyInfo;
use Firehed\PhpLsp\Utility\ReflectionHelper;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Stmt;

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

    public function allowsProperty(PropertyInfo $property): bool
    {
        return match ($this) {
            self::All => true,
            self::PublicOnly => $property->isPublic,
            self::PublicProtected => $property->isPublic || $property->isProtected,
        };
    }

    public function allowsMethod(Stmt\ClassMethod $method): bool
    {
        return match ($this) {
            self::All => true,
            self::PublicOnly => $method->isPublic(),
            self::PublicProtected => $method->isPublic() || $method->isProtected(),
        };
    }

    public function allowsConstant(Stmt\ClassConst $const): bool
    {
        return match ($this) {
            self::All => true,
            self::PublicOnly => $const->isPublic(),
            self::PublicProtected => $const->isPublic() || $const->isProtected(),
        };
    }

    /**
     * Determine visibility filter based on the relationship between an
     * enclosing class and a target class being accessed.
     */
    public static function forClassAccess(?Stmt\Class_ $enclosingClass, string $targetClassName): self
    {
        if ($enclosingClass === null) {
            return self::PublicOnly;
        }

        $enclosingClassName = $enclosingClass->namespacedName?->toString()
            ?? $enclosingClass->name?->toString();
        if ($enclosingClassName === null) {
            return self::PublicOnly;
        }

        if ($enclosingClassName === $targetClassName) {
            return self::All;
        }

        // Check direct extends in AST
        if (ScopeFinder::resolveExtendsName($enclosingClass) === $targetClassName) {
            return self::PublicProtected;
        }

        // Check deeper inheritance via reflection
        if (ReflectionHelper::getClass($enclosingClassName)?->isSubclassOf($targetClassName) === true) {
            return self::PublicProtected;
        }

        return self::PublicOnly;
    }
}
