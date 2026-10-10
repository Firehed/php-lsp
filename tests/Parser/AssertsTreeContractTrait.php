<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Checks a tree, or a node found in one, against the guarantees
 * {@see ParsedDocument} states, without comparing it to any other source.
 */
trait AssertsTreeContractTrait
{
    use DescribesSyntaxTreesTrait;

    /**
     * @param array<Stmt> $tree
     */
    private static function assertTreeMeetsContract(array $tree, string $context): void
    {
        self::assertNotSame([], $tree, "{$context}: the source must produce a tree to check");
        self::assertSubtreesMeetContract($tree, $context);
    }

    /**
     * The located node meets the guarantees, its parent links lead into the
     * parsed document's tree, and it sits in the class-like and function-like
     * that tree places at the offset.
     */
    private static function assertLocatedNodeMeetsContract(
        Node $node,
        ParsedDocument $parsed,
        int $offset,
        string $context,
    ): void {
        self::assertSubtreesMeetContract([$node], $context);

        $root = $node;
        while (($parent = $root->getAttribute('parent')) instanceof Node) {
            self::assertPositioned($parent, $context);
            $root = $parent;
        }
        self::assertContains($root, $parsed->tree, "{$context}: parent links must lead into the parsed tree");

        foreach ([Stmt\ClassLike::class, Node\FunctionLike::class] as $kind) {
            self::assertSame(
                (new NodeAtPosition())->find($parsed->tree, $offset, fn (Node $n) => $n instanceof $kind),
                $node instanceof $kind ? $node : self::ancestorOf($node, $kind),
                "{$context}: the enclosing {$kind} must be the one the tree places at the offset",
            );
        }
    }

    /**
     * Every node under $roots is positioned, has resolved names, and links to
     * the node that holds it; the roots' own parents are not checked.
     *
     * @param array<Node> $roots
     */
    private static function assertSubtreesMeetContract(array $roots, string $context): void
    {
        $check = function (Node $node, ?Node $holder) use ($context): void {
            self::assertPositioned($node, $context);
            if ($holder !== null) {
                self::assertSame(
                    $holder,
                    $node->getAttribute('parent'),
                    "{$context}: {$node->getType()} must link to the node that holds it",
                );
            }
            self::assertNameResolved($node, $context);
        };

        (new NodeTraverser(new class ($check) extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $holders = [];

            /**
             * @param \Closure(Node, ?Node): void $check
             */
            public function __construct(private readonly \Closure $check)
            {
            }

            public function enterNode(Node $node): null
            {
                ($this->check)($node, $this->holders === [] ? null : $this->holders[array_key_last($this->holders)]);
                $this->holders[] = $node;
                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->holders);
                return null;
            }
        }))->traverse($roots);
    }

    private static function assertPositioned(Node $node, string $context): void
    {
        $where = "{$context}: {$node->getType()}";
        self::assertGreaterThanOrEqual(0, $node->getStartFilePos(), "{$where} must carry startFilePos");
        self::assertGreaterThanOrEqual(0, $node->getEndFilePos(), "{$where} must carry endFilePos");
        self::assertGreaterThanOrEqual(1, $node->getStartLine(), "{$where} must carry startLine");
    }

    /**
     * Names are fully qualified, except where PHP decides at runtime (`self`,
     * `parent`, `static`, and an unqualified function or constant in a
     * namespace, which carries its `namespacedName`) and where a declaration
     * spells a name rather than refers to one (a namespace or an import).
     */
    private static function assertNameResolved(Node $node, string $context): void
    {
        if (!$node instanceof Name || $node instanceof Name\FullyQualified || $node->isSpecialClassName()) {
            return;
        }
        if ($node->getAttribute('namespacedName') instanceof Name) {
            return;
        }
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Stmt\Namespace_ || $parent instanceof Node\UseItem || $parent instanceof Stmt\GroupUse) {
            return;
        }
        self::fail("{$context}: `{$node->toString()}` at {$node->getStartFilePos()} must be fully qualified");
    }
}
