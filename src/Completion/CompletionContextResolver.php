<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * @phpstan-type MemberAccessResult array{
 *   context: CompletionContext,
 *   var: Expr,
 *   prefix: string,
 * }
 * @phpstan-type StaticAccessResult array{
 *   context: CompletionContext,
 *   class: Name,
 *   prefix: string,
 * }
 * @phpstan-type ContextResult MemberAccessResult|StaticAccessResult|null
 */
final class CompletionContextResolver
{
    /**
     * Find completion context at the given offset using AST analysis.
     *
     * @param array<Stmt> $ast
     * @return ContextResult
     */
    public function resolve(array $ast, int $offset): ?array
    {
        $node = $this->findNodeAtOffset($ast, $offset);
        if ($node === null) {
            return null;
        }

        return $this->analyzeNode($node, $offset);
    }

    /**
     * @param array<Node> $ast
     */
    private function findNodeAtOffset(array $ast, int $offset): ?Node
    {
        $finder = new class ($offset) extends NodeVisitorAbstract {
            public ?Node $found = null;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): null
            {
                $startPos = $node->getStartFilePos();
                $endPos = $node->getEndFilePos();

                // Allow cursor immediately after node end (endPos + 1)
                // This handles completion triggers like "$this->|"
                if ($startPos <= $this->offset && $this->offset <= $endPos + 1) {
                    $this->found = $node;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }

    /**
     * @return ContextResult
     */
    private function analyzeNode(Node $node, int $offset): ?array
    {
        if ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            return $this->analyzeMemberAccess($node);
        }

        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
            return $this->analyzeMemberAccess($node);
        }

        if ($node instanceof Expr\StaticPropertyFetch) {
            return $this->analyzeStaticAccess($node->class, $node->name);
        }

        if ($node instanceof Expr\StaticCall) {
            return $this->analyzeStaticAccess($node->class, $node->name);
        }

        if ($node instanceof Expr\ClassConstFetch) {
            return $this->analyzeStaticAccess($node->class, $node->name);
        }

        // Identifier or Error node - delegate to parent
        if ($node instanceof Identifier || $node instanceof Error) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node) {
                return $this->analyzeNode($parent, $offset);
            }
        }

        return null;
    }

    /**
     * @param Expr\PropertyFetch|Expr\NullsafePropertyFetch|Expr\MethodCall|Expr\NullsafeMethodCall $node
     * @return MemberAccessResult|null
     */
    private function analyzeMemberAccess(Expr $node): ?array
    {
        $name = $node->name;
        $prefix = '';

        if ($name instanceof Identifier) {
            $prefix = $name->toString();
        } elseif ($name instanceof Error) {
            $prefix = '';
        } else {
            return null;
        }

        $context = CompletionContext::fromMemberAccess($node->var);
        if ($context === CompletionContext::Unknown) {
            return null;
        }

        return [
            'context' => $context,
            'var' => $node->var,
            'prefix' => $prefix,
        ];
    }

    /**
     * @param Node $class
     * @param Node $name
     * @return StaticAccessResult|null
     */
    private function analyzeStaticAccess(Node $class, Node $name): ?array
    {
        if (!$class instanceof Name) {
            return null;
        }

        $prefix = '';
        if ($name instanceof Identifier) {
            $prefix = $name->toString();
        } elseif ($name instanceof Error) {
            $prefix = '';
        }

        $rawName = $class->toString();
        $context = match ($rawName) {
            'parent' => CompletionContext::ParentMember,
            'self', 'static' => CompletionContext::StaticMember,
            default => CompletionContext::StaticMember,
        };

        return [
            'context' => $context,
            'class' => $class,
            'prefix' => $prefix,
        ];
    }
}
