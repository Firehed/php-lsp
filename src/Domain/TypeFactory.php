<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * @deprecated sweep: source-context factory; resolution should go through
 * TypeSourceInterface, construction primitives will migrate elsewhere
 */
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
    ): ?TypeInterface {
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
                return new ClasslikeType(ClasslikeName::fromFullyQualified($name));
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

    private static function tryLateBindingType(
        string $name,
        ?string $selfContext,
        ?string $parentContext,
        bool $preserveLateBinding,
    ): ?TypeInterface {
        $keyword = LateBindingKeyword::tryFromName($name);
        if ($keyword === null) {
            return null;
        }
        if ($preserveLateBinding) {
            return new LateStaticType($keyword);
        }

        $context = match ($keyword) {
            LateBindingKeyword::Self, LateBindingKeyword::Static => $selfContext,
            LateBindingKeyword::Parent => $parentContext,
        };

        if ($context === null) {
            return new PrimitiveType($name);
        }

        return new ClasslikeType(ClasslikeName::fromFullyQualified($context));
    }
}
