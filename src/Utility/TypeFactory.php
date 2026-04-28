<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\UnionType;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class TypeFactory
{
    private const PRIMITIVES = [
        'string',
        'int',
        'float',
        'bool',
        'array',
        'object',
        'callable',
        'iterable',
        'void',
        'never',
        'mixed',
        'null',
        'true',
        'false',
    ];

    /**
     * @param class-string|null $selfContext
     * @param class-string|null $parentContext
     */
    public static function fromNode(
        ?Node $node,
        ?string $selfContext = null,
        ?string $parentContext = null,
    ): ?Type {
        if ($node === null) {
            return null;
        }

        if ($node instanceof Name) {
            /** @var class-string $fqn */
            $fqn = $node->toString();
            return new ClassName($fqn);
        }

        if ($node instanceof Identifier) {
            $name = $node->toString();

            if ($name === 'self' || $name === 'static') {
                if ($selfContext !== null) {
                    return new ClassName($selfContext);
                }
                return new PrimitiveType($name);
            }

            if ($name === 'parent') {
                if ($parentContext !== null) {
                    return new ClassName($parentContext);
                }
                return new PrimitiveType($name);
            }

            if (in_array($name, self::PRIMITIVES, true)) {
                return new PrimitiveType($name);
            }

            /** @var class-string $name */
            return new ClassName($name);
        }

        if ($node instanceof Node\NullableType) {
            $inner = self::fromNode($node->type, $selfContext, $parentContext);
            if ($inner === null) {
                return null;
            }
            return new UnionType([$inner, new PrimitiveType('null')]);
        }

        if ($node instanceof Node\UnionType) {
            $members = array_values(array_filter(
                array_map(fn (Node $n) => self::fromNode($n, $selfContext, $parentContext), $node->types),
            ));
            return new UnionType($members);
        }

        if ($node instanceof Node\IntersectionType) {
            $members = array_values(array_filter(
                array_map(fn (Node $n) => self::fromNode($n, $selfContext, $parentContext), $node->types),
            ));
            return new IntersectionType($members);
        }

        return null;
    }

    public static function fromReflection(?ReflectionType $type): ?Type
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if ($type->isBuiltin()) {
                $primitive = new PrimitiveType($name);
                if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                    return new UnionType([$primitive, new PrimitiveType('null')]);
                }
                return $primitive;
            }

            /** @var class-string $name */
            $className = new ClassName($name);
            if ($type->allowsNull()) {
                return new UnionType([$className, new PrimitiveType('null')]);
            }
            return $className;
        }

        if ($type instanceof ReflectionUnionType) {
            $members = array_values(array_filter(
                array_map(self::fromReflection(...), $type->getTypes()),
            ));
            return new UnionType($members);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $members = array_values(array_filter(
                array_map(self::fromReflection(...), $type->getTypes()),
            ));
            return new IntersectionType($members);
        }

        return null;
    }
}
