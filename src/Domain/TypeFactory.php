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
     * `static`, `self`, and `parent` are kept as {@see LateStaticType}, bound
     * where the type is used.
     */
    public static function fromNode(?Node $node): ?TypeInterface
    {
        if ($node === null) {
            return null;
        }

        if ($node instanceof Name || $node instanceof Identifier) {
            $name = $node->toString();

            $keyword = LateBindingKeyword::tryFromName($name);
            if ($keyword !== null) {
                return new LateStaticType($keyword);
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
            $inner = self::fromNode($node->type);
            // @codeCoverageIgnoreStart
            if ($inner === null) {
                throw new LogicException('NullableType inner type resolved to null');
            }
            // @codeCoverageIgnoreEnd
            return new UnionType([$inner, new PrimitiveType('null')]);
        }

        if ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
            $members = array_values(array_filter(array_map(self::fromNode(...), $node->types)));
            return $node instanceof Node\UnionType
                ? new UnionType($members)
                : new IntersectionType($members);
        }

        // @codeCoverageIgnoreStart
        throw new LogicException('Unexpected node type in type context');
        // @codeCoverageIgnoreEnd
    }
}
