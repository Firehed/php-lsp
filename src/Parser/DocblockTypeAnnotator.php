<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;

/**
 * Runs alongside {@see NameResolver} to resolve the class names in the
 * `@var`, `@return`, and `@param` (plus `psalm-`/`phpstan-`) tags of every
 * `ClassMethod`, `Property`, `ClassConst`, and `Function_`, so downstream
 * factories build parameter, return, and property types through
 * {@see \Firehed\PhpLsp\Domain\TypeFactory::merge()} without reaching for the
 * raw docblock again.
 *
 * The resolved tag map is stored on the node as the `resolvedDocblockTypes`
 * attribute, a shape of:
 * `array{return?: Type, var?: Type, params?: array<string, Type>}`.
 *
 * Docblock class names are resolved against the same {@see \PhpParser\NameContext}
 * `NameResolver` populated, so an aliased or relative name resolves the same
 * way in the docblock and in the signature.
 */
final class DocblockTypeAnnotator extends NodeVisitorAbstract
{
    public function __construct(private readonly NameResolver $nameResolver)
    {
    }

    public function enterNode(Node $node): ?int
    {
        if (
            !$node instanceof Stmt\ClassMethod
            && !$node instanceof Stmt\Property
            && !$node instanceof Stmt\ClassConst
            && !$node instanceof Stmt\Function_
            && !$node instanceof Stmt\Const_
        ) {
            return null;
        }
        $doc = $node->getDocComment()?->getText();
        if ($doc === null) {
            return null;
        }
        $context = $this->nameResolver->getNameContext();
        $resolver = static function (string $token) use ($context): string {
            return $context->getResolvedClassName(new Name($token))->toString();
        };
        $resolved = DocblockParser::extractResolvedTypedTags($doc, $resolver);
        if ($resolved !== []) {
            $node->setAttribute('resolvedDocblockTypes', $resolved);
        }
        return null;
    }
}
