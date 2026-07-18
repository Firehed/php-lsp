<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\FunctionInfo;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionException;
use ReflectionFunction;

final class DefaultFunctionRepository implements FunctionRepository
{
    public function get(string $functionName, array $ast): ?FunctionInfo
    {
        $node = $this->findFunctionInAst($functionName, $ast);
        if ($node !== null) {
            return FunctionInfo::fromNode($node);
        }

        try {
            return FunctionInfo::fromReflection(new ReflectionFunction($functionName));
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Match a user-defined function whose short name or fully-qualified
     * (namespaced) name equals the query.
     *
     * @param array<Stmt> $ast
     */
    private function findFunctionInAst(string $functionName, array $ast): ?Stmt\Function_
    {
        $finder = new class ($functionName) extends NodeVisitorAbstract {
            public ?Stmt\Function_ $found = null;

            public function __construct(private readonly string $functionName)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (!$node instanceof Stmt\Function_) {
                    return null;
                }

                $shortName = $node->name->toString();
                $fqn = $node->namespacedName?->toString();
                if ($shortName === $this->functionName || $fqn === $this->functionName) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }
}
