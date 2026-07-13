<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use PhpParser\Node\Stmt;
use PhpParser\Node\UseItem;

/**
 * Builds the {@see NameContext} in effect at a given line.
 *
 * Imports are scoped to their namespace block: a file may declare several
 * namespaces, and a `use` inside one is not in effect inside another. The
 * statements are therefore read from the block enclosing the line, rather than
 * from a flattened view of the file.
 */
final class NameContextFactory
{
    /**
     * @param array<Stmt> $ast
     * @param int $line Zero-based, as LSP positions are
     */
    public static function fromAst(array $ast, int $line): NameContext
    {
        $namespace = self::enclosingNamespace($ast, $line);

        $imports = [
            Stmt\Use_::TYPE_NORMAL => [],
            Stmt\Use_::TYPE_FUNCTION => [],
            Stmt\Use_::TYPE_CONSTANT => [],
        ];

        foreach (self::statementsIn($ast, $namespace) as $stmt) {
            if ($stmt instanceof Stmt\Use_) {
                foreach ($stmt->uses as $use) {
                    $imports[self::typeOf($use, $stmt->type)][self::aliasOf($use)] = $use->name->toString();
                }
            } elseif ($stmt instanceof Stmt\GroupUse) {
                $prefix = $stmt->prefix->toString();
                foreach ($stmt->uses as $use) {
                    $imports[self::typeOf($use, $stmt->type)][self::aliasOf($use)]
                        = $prefix . '\\' . $use->name->toString();
                }
            }
        }

        return new NameContext(
            namespace: $namespace?->name?->toString() ?? '',
            classImports: $imports[Stmt\Use_::TYPE_NORMAL],
            functionImports: $imports[Stmt\Use_::TYPE_FUNCTION],
            constantImports: $imports[Stmt\Use_::TYPE_CONSTANT],
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function enclosingNamespace(array $ast, int $line): ?Stmt\Namespace_
    {
        foreach ($ast as $stmt) {
            if (!$stmt instanceof Stmt\Namespace_) {
                continue;
            }
            // AST lines are one-based.
            if ($stmt->getStartLine() <= $line + 1 && $line + 1 <= $stmt->getEndLine()) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * The statements whose imports are in effect: those of the enclosing
     * namespace block, or the file's own when the line is outside any block.
     *
     * @param array<Stmt> $ast
     * @return array<Stmt>
     */
    private static function statementsIn(array $ast, ?Stmt\Namespace_ $namespace): array
    {
        if ($namespace === null) {
            return $ast;
        }

        return $namespace->stmts;
    }

    /**
     * A group use may mix kinds (`use Foo\{function bar, const BAZ, Qux}`), in
     * which case the item carries the type; otherwise the statement does.
     *
     * @param Stmt\Use_::TYPE_* $statementType
     * @return Stmt\Use_::TYPE_NORMAL|Stmt\Use_::TYPE_FUNCTION|Stmt\Use_::TYPE_CONSTANT
     */
    private static function typeOf(UseItem $use, int $statementType): int
    {
        $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statementType : $use->type;

        if ($type === Stmt\Use_::TYPE_UNKNOWN) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('A use statement and its items cannot both be untyped');
            // @codeCoverageIgnoreEnd
        }

        return $type;
    }

    private static function aliasOf(UseItem $use): string
    {
        return $use->alias?->toString() ?? $use->name->getLast();
    }
}
