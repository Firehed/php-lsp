<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * For every `ClassMethod`, `Property`, `ClassConst`, `Function_`, and `Const_`
 * that carries a docblock, hands the doc text plus the current namespace and
 * class-import table to {@see DocblockParser}. The resolved tag map is stored
 * on the node as the `resolvedDocblockTypes` attribute, a shape of
 * `array{return?: Type, var?: Type, params?: array<string, Type>}`.
 *
 * Tracks namespace and `use` state itself while traversing (alongside
 * php-parser's `NameResolver`), so it can hand the docblock parser a plain
 * namespace + alias map without reaching into `NameResolver` internals.
 */
final class DocblockTypeAnnotator extends NodeVisitorAbstract
{
    private string $namespace = '';

    /** @var array<string, string> alias => fully-qualified name */
    private array $aliases = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';
            $this->aliases = [];
            return null;
        }
        if ($node instanceof Stmt\Use_) {
            $this->recordUses($node->type, '', $node->uses);
            return null;
        }
        if ($node instanceof Stmt\GroupUse) {
            $this->recordUses($node->type, $node->prefix->toString() . '\\', $node->uses);
            return null;
        }
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
        $resolved = DocblockParser::extractResolvedTypedTags($doc, $this->namespace, $this->aliases);
        if ($resolved !== []) {
            $node->setAttribute('resolvedDocblockTypes', $resolved);
        }
        return null;
    }

    /**
     * @param array<Stmt\UseUse> $uses
     */
    private function recordUses(int $groupType, string $prefix, array $uses): void
    {
        foreach ($uses as $use) {
            $itemType = $use->type;
            $effectiveType = $itemType === Stmt\Use_::TYPE_UNKNOWN ? $groupType : $itemType;
            if ($effectiveType !== Stmt\Use_::TYPE_NORMAL && $effectiveType !== Stmt\Use_::TYPE_UNKNOWN) {
                continue;
            }
            $this->aliases[$use->getAlias()->toString()] = $prefix . $use->name->toString();
        }
    }
}
