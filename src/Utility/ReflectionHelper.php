<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use ReflectionClass;

final class ReflectionHelper
{
    /**
     * Get a ReflectionClass for a class, interface, or trait if it exists.
     *
     * @template T of object
     * @param class-string<T>|string $className
     * @return ($className is class-string<T> ? ReflectionClass<T> : ReflectionClass<object>|null)
     */
    public static function getClass(string $className): ?ReflectionClass
    {
        if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
            return null;
        }
        return new ReflectionClass($className);
    }
}
