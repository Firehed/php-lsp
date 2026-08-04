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
 * Reads the class-likes, functions and constants a parsed file declares, so a
 * name -> file map can be derived for an `autoload.files` entry, which Composer
 * addresses by no name at all (Plan 0002 §3).
 *
 * The blind spots are Plan 0002 §3's locate-only limitation rather than oversights:
 * a `define()` whose name — or whose call — resolves only at runtime, and anything
 * reached only through a `require`/`include`, which is not followed.
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
            public array $classLikes = [];

            /** @var list<QualifiedName> */
            public array $functions = [];

            /** @var list<QualifiedName> */
            public array $constants = [];

            public function enterNode(Node $node): null
            {
                // An anonymous class has no name, so there is nothing to index.
                if ($node instanceof Stmt\ClassLike && $node->name !== null) {
                    $this->classLikes[] = self::qualify($node->namespacedName ?? $node->name);
                    return null;
                }

                if ($node instanceof Stmt\Function_) {
                    $this->functions[] = self::qualify($node->namespacedName ?? $node->name);
                    return null;
                }

                if ($node instanceof Stmt\Const_) {
                    foreach ($node->consts as $const) {
                        $this->constants[] = self::qualify($const->namespacedName ?? $const->name);
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

                $name = $this->constantNameArgument($node);
                if (!$name instanceof Scalar\String_) {
                    return;
                }

                $this->constants[] = QualifiedName::fromFullyQualified($name->value);
            }

            /**
             * A named argument may only follow the positional ones, so the first
             * unnamed argument is the name. A named one is the name only if it says
             * so — read positionally, `define(value: 'x', constant_name: 'Y')` would
             * declare a constant called `x`.
             */
            private function constantNameArgument(Expr\FuncCall $node): ?Node\Expr
            {
                foreach ($node->args as $argument) {
                    if (!$argument instanceof Node\Arg) {
                        continue;
                    }

                    if ($argument->name === null || $argument->name->toString() === 'constant_name') {
                        return $argument->value;
                    }
                }

                return null;
            }

            /**
             * ParserService always runs NameResolver, so `namespacedName` is set.
             * Falling back to the declared name keeps this total for an AST parsed
             * without it, where the two differ only under a namespace.
             */
            private static function qualify(Node\Name|Node\Identifier $name): QualifiedName
            {
                return QualifiedName::fromFullyQualified($name->toString());
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return new FileDeclarations($visitor->classLikes, $visitor->functions, $visitor->constants);
    }
}
