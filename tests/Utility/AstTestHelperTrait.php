<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

trait AstTestHelperTrait
{
    /**
     * @return array<Stmt>
     */
    private static function parseWithParents(string $code): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->traverse($ast);

        return $ast;
    }

    /**
     * @param array<Stmt> $ast
     */
    private static function findVariableNode(string $name, array $ast): ?Variable
    {
        $visitor = new class ($name) extends \PhpParser\NodeVisitorAbstract {
            public ?Variable $found = null;

            public function __construct(private readonly string $name)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Variable && $node->name === $this->name) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }
}
