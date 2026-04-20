<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node\Stmt;
use ReflectionMethod;
use ReflectionProperty;

final class VisibilityFilter
{
    public static function isMethodAccessible(Stmt\ClassMethod $method, AccessContext $context): bool
    {
        return match ($context) {
            AccessContext::SameClass => true,
            AccessContext::Subclass => !$method->isPrivate(),
            AccessContext::External => $method->isPublic(),
        };
    }

    public static function isPropertyAccessible(Stmt\Property $property, AccessContext $context): bool
    {
        return match ($context) {
            AccessContext::SameClass => true,
            AccessContext::Subclass => !$property->isPrivate(),
            AccessContext::External => $property->isPublic(),
        };
    }

    public static function isReflectionMethodAccessible(ReflectionMethod $method, AccessContext $context): bool
    {
        return match ($context) {
            AccessContext::SameClass => true,
            AccessContext::Subclass => !$method->isPrivate(),
            AccessContext::External => $method->isPublic(),
        };
    }

    public static function isReflectionPropertyAccessible(ReflectionProperty $property, AccessContext $context): bool
    {
        return match ($context) {
            AccessContext::SameClass => true,
            AccessContext::Subclass => !$property->isPrivate(),
            AccessContext::External => $property->isPublic(),
        };
    }
}
