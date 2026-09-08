<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

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
     * Parse a docblock type whose class names are already fully qualified into a
     * {@see Type}. Handles `T[]`, `array<T>`, `list<T>`, `iterable<T>`, `?T`,
     * unions of them, and generics on a class-like (`Collection<User>`). Name
     * resolution happens upstream in {@see \Firehed\PhpLsp\Parser\DocblockTypeAnnotator}.
     */
    public static function fromDocblockType(string $resolved): ?Type
    {
        $trimmed = trim($resolved);
        if ($trimmed === '') {
            return null;
        }

        $unionParts = self::splitTopLevel($trimmed, '|');
        if (count($unionParts) > 1) {
            $members = [];
            foreach ($unionParts as $part) {
                $member = self::fromDocblockType($part);
                if ($member !== null) {
                    $members[] = $member;
                }
            }
            return count($members) === 1 ? $members[0] : new UnionType($members);
        }

        if (str_starts_with($trimmed, '?')) {
            $inner = self::fromDocblockType(substr($trimmed, 1));
            if ($inner === null) {
                return null;
            }
            return new UnionType([$inner, new PrimitiveType('null')]);
        }

        if (str_ends_with($trimmed, '[]')) {
            $inner = self::fromDocblockType(substr($trimmed, 0, -2));
            $args = $inner !== null ? [$inner] : [];
            return new PrimitiveType('array', $args);
        }

        if (str_ends_with($trimmed, '>')) {
            $open = strpos($trimmed, '<');
            if ($open !== false) {
                $base = substr($trimmed, 0, $open);
                $argsText = substr($trimmed, $open + 1, -1);
                $argParts = self::splitTopLevel($argsText, ',');
                $lastArg = self::fromDocblockType(end($argParts));
                $typeArgs = $lastArg !== null ? [$lastArg] : [];
                return self::containerFromBase($base, $typeArgs);
            }
        }

        return self::containerFromBase($trimmed, []);
    }

    /**
     * Docblock type attached to the node's `@var` tag by
     * {@see \Firehed\PhpLsp\Parser\DocblockTypeAnnotator}, or null when absent.
     */
    public static function docblockVarType(Node $node): ?Type
    {
        return self::docblockTypeAt($node, 'var');
    }

    /**
     * Docblock type attached to the node's `@return` tag by
     * {@see \Firehed\PhpLsp\Parser\DocblockTypeAnnotator}, or null when absent.
     */
    public static function docblockReturnType(Node $node): ?Type
    {
        return self::docblockTypeAt($node, 'return');
    }

    /**
     * Docblock type attached to the node's `@param $name` tag by
     * {@see \Firehed\PhpLsp\Parser\DocblockTypeAnnotator}, or null when absent.
     */
    public static function docblockParamType(Node $node, string $paramName): ?Type
    {
        $paramTypes = self::resolvedDocblockTypes($node)['params'] ?? [];
        return isset($paramTypes[$paramName]) ? self::fromDocblockType($paramTypes[$paramName]) : null;
    }

    private static function docblockTypeAt(Node $node, string $key): ?Type
    {
        $tags = self::resolvedDocblockTypes($node);
        $value = $tags[$key] ?? null;
        return is_string($value) ? self::fromDocblockType($value) : null;
    }

    /**
     * @return array{return?: string, var?: string, params?: array<string, string>}
     */
    private static function resolvedDocblockTypes(Node $node): array
    {
        /** @var array{return?: string, var?: string, params?: array<string, string>} */
        return $node->getAttribute('resolvedDocblockTypes', []);
    }

    /**
     * Combine a native type with a docblock type. Native wins on conflict; a
     * docblock supplies the value type for a native `array`, `iterable`, or
     * class-like that has none.
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
        $value = $docblock->valueType();
        if ($value === null) {
            return $native;
        }
        if ($native instanceof PrimitiveType && $native->acceptsValueType()) {
            return $native->withValueType($value);
        }
        if ($native instanceof ClassName) {
            return new ClassName($native->fqn, [$value]);
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

    /**
     * @param list<Type> $typeArgs
     */
    private static function containerFromBase(string $base, array $typeArgs): Type
    {
        if ($base === 'list') {
            return new PrimitiveType('array', $typeArgs);
        }
        if (in_array($base, PrimitiveType::NAMES, true)) {
            return new PrimitiveType($base, $typeArgs);
        }
        return new ClassName($base, $typeArgs);
    }

    /**
     * Split at top-level `$delim` characters, respecting `<...>` depth.
     *
     * @return non-empty-list<string>
     */
    private static function splitTopLevel(string $input, string $delim): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $ch = $input[$i];
            if ($ch === '<') {
                $depth++;
            } elseif ($ch === '>') {
                $depth--;
            } elseif ($ch === $delim && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;
        return $parts;
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
