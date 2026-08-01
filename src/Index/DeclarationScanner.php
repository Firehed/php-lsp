<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads the functions and constants a parsed file declares, so a name -> file map
 * can be derived for the two symbol namespaces Composer cannot address by name
 * (Plan 0002 §3).
 *
 * Qualified names come from the `namespacedName` attribute {@see \PhpParser\NodeVisitor\NameResolver}
 * already sets during {@see \Firehed\PhpLsp\Parser\ParserService::parse()}, rather
 * than from tracking namespace statements by hand.
 *
 * `define()` is recognised only when its name is a literal string. A computed name
 * is produced by a runtime call and cannot be known from a static parse, so it is
 * skipped — the locate-only limitation Plan 0002 §3 scopes out, not an oversight.
 */
final class DeclarationScanner
{
    /**
     * @param array<Stmt> $ast
     */
    public function scan(array $ast): FileDeclarations
    {
        $visitor = new class () extends NodeVisitorAbstract {
            /** @var list<QualifiedName> */
            public array $functions = [];

            /** @var list<QualifiedName> */
            public array $constants = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Stmt\Function_) {
                    $this->addFrom($node->namespacedName, $this->functions);
                    return null;
                }

                if ($node instanceof Stmt\Const_) {
                    foreach ($node->consts as $const) {
                        $this->addFrom($const->namespacedName, $this->constants);
                    }
                    return null;
                }

                if ($node instanceof Expr\FuncCall) {
                    $this->addDefinedConstant($node);
                }

                return null;
            }

            /**
             * `define()` writes into the global namespace whatever namespace it is
             * called from, so its literal argument is already the qualified name.
             */
            private function addDefinedConstant(Expr\FuncCall $node): void
            {
                if (!$node->name instanceof Node\Name || $node->name->toLowerString() !== 'define') {
                    return;
                }

                $first = $node->args[0] ?? null;
                if (!$first instanceof Node\Arg || !$first->value instanceof Scalar\String_) {
                    return;
                }

                $this->constants[] = QualifiedName::fromFullyQualified($first->value->value);
            }

            /**
             * @param list<QualifiedName> $into
             */
            private function addFrom(?Node\Name $name, array &$into): void
            {
                if ($name === null) {
                    // @codeCoverageIgnoreStart
                    // NameResolver populates namespacedName for every declaration it
                    // visits, and ParserService always runs it; a declaration without
                    // one cannot be reached through the parser this server uses.
                    return;
                    // @codeCoverageIgnoreEnd
                }

                $into[] = QualifiedName::fromFullyQualified($name->toString());
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return new FileDeclarations($visitor->functions, $visitor->constants);
    }
}
