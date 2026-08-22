<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use PhpParser\NodeVisitorAbstract;

/**
 * A class named by a computed value rather than literally, in every syntactic
 * form. Such a reference is invisible to the §8.1 rules, which read the name.
 */
final class DynamicClassReference
{
    public const string SOME_CONSTANT = 'x';

    public static ?self $instance = null;

    public static function make(): self
    {
        return new self();
    }

    public function construct(string $className): object
    {
        return new $className();
    }

    public function check(object $value, string $className): bool
    {
        return $value instanceof $className;
    }

    public function nameOf(object $value): string
    {
        return $value::class;
    }

    public function constantOf(string $className): string
    {
        return $className::SOME_CONSTANT;
    }

    public function callStatically(string $className): self
    {
        return $className::make();
    }

    public function propertyOf(string $className): ?self
    {
        return $className::$instance;
    }

    public function literalFormsAreFine(object $value): bool
    {
        return $value instanceof self
            && self::make() instanceof self
            && self::$instance === null
            && self::SOME_CONSTANT !== self::class;
    }

    public function anonymousClassIsNotAComputedName(): NodeVisitorAbstract
    {
        return new class () extends NodeVisitorAbstract {
        };
    }
}
