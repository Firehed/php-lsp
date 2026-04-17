<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ClassFinder;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class DefinitionHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly SymbolIndex $symbolIndex,
        private readonly ?ComposerClassLocator $classLocator = null,
        private readonly ?TypeResolverInterface $typeResolver = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/definition';
    }

    /**
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        // Get the document
        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        // Parse the document
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        // Find node at position
        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        // Check if this is a method name (Identifier) in a method call
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->handleStaticMethodDefinition($parent, $ast);
            }

            // Instance method call: $obj->method()
            if ($parent instanceof MethodCall) {
                return $this->handleInstanceMethodDefinition($parent, $ast);
            }
        }

        // Class/interface/trait/enum reference
        if ($node instanceof Name) {
            return $this->handleNameDefinition($node);
        }

        return null;
    }

    /**
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function handleNameDefinition(Name $node): ?array
    {
        $symbolName = $this->resolveName($node);

        // Look up in index first (for open files)
        $symbol = $this->symbolIndex->findByFqn($symbolName);
        if ($symbol === null) {
            $matches = $this->symbolIndex->findByName($symbolName);
            $symbol = $matches[0] ?? null;
        }

        if ($symbol !== null) {
            return $symbol->location->toLspLocation();
        }

        // Not in index - try to locate via Composer autoload
        return $this->locateViaComposer($symbolName)?->toLspLocation();
    }

    /**
     * Handle definition for static method call: ClassName::method()
     *
     * @param array<Stmt> $ast
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function handleStaticMethodDefinition(StaticCall $call, array $ast): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = $this->resolveName($class);

        // Handle parent:: - resolve to actual parent class name
        if ($className === 'parent') {
            $enclosingClass = $this->findEnclosingClassNode($call);
            if ($enclosingClass instanceof Stmt\Class_ && $enclosingClass->extends !== null) {
                $className = $this->resolveName($enclosingClass->extends);
            } else {
                return null;
            }
        }

        // Handle self:: and static:: - resolve to enclosing class
        if ($className === 'self' || $className === 'static') {
            $enclosingClassName = $this->findEnclosingClassName($call);
            if ($enclosingClassName === null) {
                return null;
            }
            $className = $enclosingClassName;
        }

        return $this->findMethodDefinition($className, $methodName->toString(), $ast);
    }

    /**
     * Find the enclosing class-like node for a given node.
     */
    private function findEnclosingClassNode(
        Node $node,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if (
                $current instanceof Stmt\Class_
                || $current instanceof Stmt\Interface_
                || $current instanceof Stmt\Trait_
                || $current instanceof Stmt\Enum_
            ) {
                return $current;
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * Handle definition for instance method call: $obj->method()
     *
     * @param array<Stmt> $ast
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function handleInstanceMethodDefinition(MethodCall $call, array $ast): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveExpressionClass($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->findMethodDefinition($className, $methodName->toString(), $ast);
    }

    /**
     * Resolve the class name of an expression.
     *
     * @param array<Stmt> $ast
     */
    private function resolveExpressionClass(Node\Expr $expr, array $ast): ?string
    {
        // $this refers to the enclosing class
        if ($expr instanceof Variable && $expr->name === 'this') {
            return $this->findEnclosingClassName($expr);
        }

        // Use type resolver for other expressions
        if ($this->typeResolver !== null) {
            $scope = ScopeFinder::findEnclosingScope($expr);
            if ($scope !== null) {
                return $this->typeResolver->resolveExpressionType($expr, $scope, $ast);
            }
        }

        return null;
    }

    /**
     * Find the enclosing class-like name for a node.
     */
    private function findEnclosingClassName(Node $node): ?string
    {
        $classNode = $this->findEnclosingClassNode($node);
        if ($classNode === null || $classNode->name === null) {
            return null;
        }

        $namespacedName = $classNode->namespacedName;
        if ($namespacedName instanceof Name) {
            return $namespacedName->toString();
        }
        return $classNode->name->toString();
    }

    /**
     * Find the definition of a method in a class.
     *
     * @param array<Stmt> $ast
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function findMethodDefinition(string $className, string $methodName, array $ast): ?array
    {
        [$classNode, $classUri] = $this->findClassWithUri($className, $ast);

        if ($classNode === null || $classUri === null) {
            return null;
        }

        // Search for the method in the class
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                return $this->createMethodLocationWithUri($stmt, $classUri);
            }
        }

        // PHP method resolution order: class -> traits -> parent
        // Search in traits first (before parent)
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $traitName) {
                    $traitResult = $this->findMethodDefinition($this->resolveName($traitName), $methodName, $ast);
                    if ($traitResult !== null) {
                        return $traitResult;
                    }
                }
            }
        }

        // Search in parent class
        if ($classNode instanceof Stmt\Class_ && $classNode->extends !== null) {
            $parentResult = $this->findMethodDefinition($this->resolveName($classNode->extends), $methodName, $ast);
            if ($parentResult !== null) {
                return $parentResult;
            }
        }

        return null;
    }

    /**
     * Create a Location for a method definition with a known URI.
     *
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function createMethodLocationWithUri(Stmt\ClassMethod $method, string $uri): ?array
    {
        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if ($startLine === -1 || $endLine === -1) {
            return null;
        }

        // Convert to 0-based line numbers
        $location = new Location(
            $uri,
            $startLine - 1,
            0,
            $endLine - 1,
            0,
        );

        return $location->toLspLocation();
    }

    /**
     * Get the URI for a class node.
     */
    private function getClassUri(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $classNode): ?string
    {
        // First check if this class is in the symbol index
        $className = $classNode->namespacedName?->toString() ?? $classNode->name?->toString();
        if ($className !== null) {
            $symbol = $this->symbolIndex->findByFqn($className);
            if ($symbol !== null) {
                return $symbol->location->uri;
            }

            // Try to locate via Composer
            if ($this->classLocator !== null) {
                $filePath = $this->classLocator->locateClass($className);
                if ($filePath !== null) {
                    return 'file://' . $filePath;
                }
            }
        }

        return null;
    }

    /**
     * Find a class node and its URI.
     *
     * Searches in order:
     * 1. Current AST
     * 2. SymbolIndex (for open files)
     * 3. Composer autoload (for external dependencies)
     *
     * @param array<Stmt> $ast
     * @return array{
     *   0: Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null,
     *   1: string|null,
     * }
     */
    private function findClassWithUri(string $className, array $ast): array
    {
        // First, try to find the class in the current AST
        $classNode = ClassFinder::findInAst($className, $ast);
        if ($classNode !== null) {
            $uri = $this->getClassUri($classNode);
            return [$classNode, $uri];
        }

        // Try to find via SymbolIndex
        $symbol = $this->symbolIndex->findByFqn($className);
        if ($symbol === null) {
            $matches = $this->symbolIndex->findByName($className);
            $symbol = $matches[0] ?? null;
        }

        if ($symbol !== null) {
            $classUri = $symbol->location->uri;
            $document = $this->documentManager->get($classUri);
            if ($document === null) {
                $filePath = str_starts_with($classUri, 'file://') ? substr($classUri, 7) : $classUri;
                $content = @file_get_contents($filePath);
                if ($content !== false) {
                    $document = new TextDocument($classUri, 'php', 0, $content);
                }
            }

            if ($document !== null) {
                $classAst = $this->parser->parse($document);
                if ($classAst !== null) {
                    $classNode = ClassFinder::findInAst($className, $classAst);
                    if ($classNode !== null) {
                        return [$classNode, $classUri];
                    }
                }
            }
        }

        // Try Composer locator as fallback
        if ($this->classLocator !== null) {
            $classNode = ClassFinder::findWithLocator($className, $ast, $this->classLocator, $this->parser);
            if ($classNode !== null) {
                $filePath = $this->classLocator->locateClass($className);
                if ($filePath !== null) {
                    return [$classNode, 'file://' . $filePath];
                }
            }
        }

        return [null, null];
    }

    private function locateViaComposer(string $className): ?Location
    {
        if ($this->classLocator === null) {
            return null;
        }

        $filePath = $this->classLocator->locateClass($className);
        if ($filePath === null) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $uri = 'file://' . $filePath;
        $document = new TextDocument($uri, 'php', 0, $content);

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        // Extract symbols and find our class
        $extractor = new SymbolExtractor();
        $symbols = $extractor->extract($document, $ast);

        foreach ($symbols as $symbol) {
            if ($symbol->fullyQualifiedName === $className) {
                return $symbol->location;
            }
        }

        // Fallback: return start of file
        return new Location($uri, 0, 0, 0, 0);
    }

    /**
     * Get the fully qualified name from a Name node, using resolvedName if available.
     */
    private function resolveName(Name $node): string
    {
        $resolved = $node->getAttribute('resolvedName');
        return $resolved instanceof Name
            ? $resolved->toString()
            : $node->toString();
    }
}
