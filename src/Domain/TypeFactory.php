<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode as DocIntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class TypeFactory
{
    /**
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
                $fqn = $resolvedName instanceof Name
                    ? $resolvedName->toString()
                    : $name;
                return new ClassName($fqn);
            }

            if (in_array($name, PrimitiveType::NAMES, true)) {
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

        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $mapper = fn (Node $n) => self::fromNode($n, $selfContext, $parentContext, $preserveLateBinding);
            $members = array_values(array_filter(array_map($mapper, $node->types)));
            return $node instanceof Node\UnionType
                ? new UnionType($members)
                : new IntersectionType($members);
        }

        // @codeCoverageIgnoreStart
        throw new LogicException('Unexpected node type in type context');
        // @codeCoverageIgnoreEnd
    }

    public static function className(string $fqn): ClassName
    {
        return new ClassName($fqn);
    }

    public static function primitive(string $name): PrimitiveType
    {
        return new PrimitiveType($name);
    }

    /**
     * Build a union from resolved parts. A single-member "union" collapses to
     * that member, matching how PHP's type system treats it.
     *
     * @param non-empty-list<Type> $members
     */
    public static function union(array $members): Type
    {
        if (count($members) === 1) {
            return $members[0];
        }
        return new UnionType($members);
    }

    /**
     * Convert a resolved phpstan/phpdoc-parser `TypeNode` into a Domain `Type`.
     * Every identifier in the tree must already be either a `PrimitiveType`
     * name or a fully qualified class name — name resolution belongs to
     * `DocblockTypeAnnotator`, not here. Returns `null` for shapes the Domain
     * layer does not model yet (callables, conditionals, array shapes).
     */
    public static function fromDocblockType(TypeNode $node): ?Type
    {
        if ($node instanceof IdentifierTypeNode) {
            $name = $node->name;
            if (in_array($name, PrimitiveType::NAMES, true)) {
                return new PrimitiveType($name);
            }
            return new ClassName($name);
        }

        if ($node instanceof ArrayTypeNode) {
            $inner = self::fromDocblockType($node->type);
            return $inner === null
                ? new PrimitiveType('array')
                : new PrimitiveType('array', [$inner]);
        }

        if ($node instanceof NullableTypeNode) {
            $inner = self::fromDocblockType($node->type);
            if ($inner === null) {
                return null;
            }
            return new UnionType([$inner, new PrimitiveType('null')]);
        }

        if ($node instanceof GenericTypeNode) {
            return self::fromDocblockGeneric($node);
        }

        if ($node instanceof UnionTypeNode) {
            $members = self::mapTypeNodes($node->types);
            return $members === null ? null : new UnionType($members);
        }

        if ($node instanceof DocIntersectionTypeNode) {
            $members = self::mapTypeNodes($node->types);
            return $members === null ? null : new IntersectionType($members);
        }

        return null;
    }

    /**
     * Read one entry from the `resolvedDocblockTypes` attribute
     * {@see \Firehed\PhpLsp\Parser\DocblockTypeAnnotator} sets, and turn it
     * into a `Type`. Every consumer takes a typed `Node` and this factory
     * owns the `mixed`-shape boundary the raw attribute API exposes.
     */
    public static function fromDocblockNode(Node $node, string $key): ?Type
    {
        $attribute = $node->getAttribute('resolvedDocblockTypes');
        if (!is_array($attribute)) {
            return null;
        }
        $typeNode = $attribute[$key] ?? null;
        if (!$typeNode instanceof TypeNode) {
            return null;
        }
        return self::fromDocblockType($typeNode);
    }

    /**
     * Reconcile a native type declaration with a docblock's refinement of it.
     * The native declaration wins on the outer shape; a docblock only fills in
     * the value type when the native says `array` or `iterable` or names the
     * same class without generics of its own. Every caller reads one `Type`
     * with no awareness of where each part came from.
     */
    public static function merge(?Type $native, ?Type $docblock): ?Type
    {
        if ($native === null) {
            return $docblock;
        }
        if ($docblock === null) {
            return $native;
        }
        if ($native->valueType() !== null) {
            return $native;
        }
        $docblockValue = $docblock->valueType();
        if ($docblockValue === null) {
            return $native;
        }
        if ($native instanceof PrimitiveType) {
            if ($native->name === 'array' || $native->name === 'iterable') {
                return new PrimitiveType($native->name, [$docblockValue]);
            }
            return $native;
        }
        if ($native instanceof ClassName && $docblock instanceof ClassName && $native->fqn === $docblock->fqn) {
            return new ClassName($native->fqn, [$docblockValue]);
        }
        return $native;
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
        throw new LogicException('Unexpected ReflectionType kind');
        // @codeCoverageIgnoreEnd
    }

    private static function fromDocblockGeneric(GenericTypeNode $node): Type
    {
        $outer = $node->type->name;
        $args = $node->genericTypes;
        // For array<K,V>, iterable<K,V>, and list<K,V> the value is the last arg;
        // for the single-arg form the value is that arg. Non-generic containers
        // hold no useful key/value shape here.
        $lastArg = $args[count($args) - 1] ?? null;
        $valueType = $lastArg === null ? null : self::fromDocblockType($lastArg);

        if ($outer === 'array' || $outer === 'list' || $outer === 'non-empty-list' || $outer === 'non-empty-array') {
            return $valueType === null
                ? new PrimitiveType('array')
                : new PrimitiveType('array', [$valueType]);
        }
        if ($outer === 'iterable') {
            return $valueType === null
                ? new PrimitiveType('iterable')
                : new PrimitiveType('iterable', [$valueType]);
        }
        if (in_array($outer, PrimitiveType::NAMES, true)) {
            return new PrimitiveType($outer);
        }
        return $valueType === null
            ? new ClassName($outer)
            : new ClassName($outer, [$valueType]);
    }

    /**
     * @param array<TypeNode> $nodes
     * @return ?list<Type>
     */
    private static function mapTypeNodes(array $nodes): ?array
    {
        $out = [];
        foreach ($nodes as $inner) {
            $mapped = self::fromDocblockType($inner);
            if ($mapped === null) {
                return null;
            }
            $out[] = $mapped;
        }
        return $out;
    }

    private static function tryLateBindingType(
        string $name,
        ?string $selfContext,
        ?string $parentContext,
        bool $preserveLateBinding,
    ): ?Type {
        $keyword = LateBindingKeyword::tryFromName($name);
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
