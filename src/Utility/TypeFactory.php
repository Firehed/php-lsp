<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\IntersectionType;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\LateStaticType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\UnionType;
use LogicException;
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
     * @param bool $preserveLateBinding If true, returns LateStaticType for static/self/parent
     */
    public static function fromNode(
        ?Node $node,
        ?string $selfContext = null,
        ?string $parentContext = null,
        bool $preserveLateBinding = false,
    ): ?Type {
        if ($node === null) {
            return null;
        }

        if ($node instanceof Name || $node instanceof Identifier) {
            $name = $node->toString();

            $lateBindingType = self::tryLateBindingType(
                $name,
                $selfContext,
                $parentContext,
                $preserveLateBinding,
            );
            if ($lateBindingType !== null) {
                return $lateBindingType;
            }

            if ($node instanceof Name) {
                $resolvedName = $node->getAttribute('resolvedName');
                /** @var class-string $fqn */
                $fqn = $resolvedName instanceof Name
                    ? $resolvedName->toString()
                    : $name;
                return new ClassName($fqn);
            }

            if (in_array($name, self::PRIMITIVES, true)) {
                return new PrimitiveType($name);
            }

            // @codeCoverageIgnoreStart
            throw new LogicException("Unexpected Identifier in type context: $name");
            // @codeCoverageIgnoreEnd
        }

        if ($node instanceof Node\NullableType) {
            $inner = self::fromNode($node->type, $selfContext, $parentContext, $preserveLateBinding);
            // @codeCoverageIgnoreStart
            if ($inner === null) {
                throw new LogicException('NullableType inner type resolved to null');
            }
            // @codeCoverageIgnoreEnd
            return new UnionType([$inner, new PrimitiveType('null')]);
        }

        if ($node instanceof Node\UnionType) {
            $mapper = fn (Node $n) => self::fromNode($n, $selfContext, $parentContext, $preserveLateBinding);
            $members = array_values(array_filter(array_map($mapper, $node->types)));
            return new UnionType($members);
        }

        if ($node instanceof Node\IntersectionType) {
            $mapper = fn (Node $n) => self::fromNode($n, $selfContext, $parentContext, $preserveLateBinding);
            $members = array_values(array_filter(array_map($mapper, $node->types)));
            return new IntersectionType($members);
        }

        // @codeCoverageIgnoreStart
        throw new LogicException('Unexpected node type: ' . $node::class);
        // @codeCoverageIgnoreEnd
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

        // @codeCoverageIgnoreStart
        throw new LogicException('Unexpected ReflectionType: ' . $type::class);
        // @codeCoverageIgnoreEnd
    }

    /**
     * @param class-string|null $selfContext
     * @param class-string|null $parentContext
     */
    private static function tryLateBindingType(
        string $name,
        ?string $selfContext,
        ?string $parentContext,
        bool $preserveLateBinding,
    ): ?Type {
        $keyword = LateBindingKeyword::tryFrom($name);
        if ($keyword === null) {
            return null;
        }

        $context = match ($keyword) {
            LateBindingKeyword::Self, LateBindingKeyword::Static => $selfContext,
            LateBindingKeyword::Parent => $parentContext,
        };

        if ($context === null) {
            return new PrimitiveType($name);
        }

        if ($preserveLateBinding) {
            return new LateStaticType($keyword, new ClassName($context));
        }

        return new ClassName($context);
    }
}
