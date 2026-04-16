<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Utility;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class ClassFinder
{
    /**
     * Find a class, interface, trait, or enum in the given AST by name.
     *
     * Matches by fully-qualified name or short name.
     *
     * @param array<Stmt> $ast
     */
    public static function findInAst(
        string $className,
        array $ast,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;
            private string $namespace = '';

            public function __construct(private readonly string $className)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }

                if (
                    $node instanceof Stmt\Class_
                    || $node instanceof Stmt\Interface_
                    || $node instanceof Stmt\Trait_
                    || $node instanceof Stmt\Enum_
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

                    if ($fqn === $this->className || $name === $this->className) {
                        $this->found = $node;
                        return NodeTraverser::STOP_TRAVERSAL;
                    }
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
     * Find a class by first checking the given AST, then falling back to
     * locating the class file via Composer and parsing it.
     *
     * @param array<Stmt> $ast
     */
    public static function findWithLocator(
        string $className,
        array $ast,
        ?ComposerClassLocator $locator,
        ParserService $parser,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        // First check the provided AST
        $found = self::findInAst($className, $ast);
        if ($found !== null) {
            return $found;
        }

        // If not found and we have a locator, try to find the class file
        if ($locator === null) {
            return null;
        }

        $filePath = $locator->locateClass($className);
        if ($filePath === null) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
        $externalAst = $parser->parse($externalDoc);
        if ($externalAst === null) {
            return null;
        }

        return self::findInAst($className, $externalAst);
    }
}
