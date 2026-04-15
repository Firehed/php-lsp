<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class TypeFormatter
{
    /**
     * Format a PhpParser type node to its string representation.
     */
    public static function formatNode(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . self::formatNode($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(self::formatNode(...), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(self::formatNode(...), $type->types));
        }
        return null;
    }

    /**
     * Format a reflection type to its string representation.
     */
    public static function formatReflection(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' . $name : $name;
        }
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(self::formatReflection(...), $type->getTypes()));
        }
        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(self::formatReflection(...), $type->getTypes()));
        }
        return (string) $type;
    }
}
