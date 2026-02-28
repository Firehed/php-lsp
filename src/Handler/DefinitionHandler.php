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
use Firehed\PhpLsp\TypeInference\TypeInferenceInterface;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
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
        private readonly ?TypeInferenceInterface $typeInference = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/definition';
    }

    /**
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
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

        // Handle Identifier nodes (method names, property names)
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');

            // Method call: $obj->method()
            if ($parent instanceof MethodCall) {
                return $this->handleMethodDefinition($parent, $document);
            }

            // Property fetch: $obj->property
            if ($parent instanceof PropertyFetch) {
                return $this->handlePropertyDefinition($parent, $document);
            }
        }

        // Handle Name nodes (class references)
        if (!$node instanceof Name) {
            return null;
        }

        // Use the resolved name if available (handles use statements)
        $resolvedName = $node->getAttribute('resolvedName');
        $symbolName = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $node->toString();

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
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
     */
    private function handleMethodDefinition(MethodCall $call, TextDocument $document): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveObjectType($call->var, $document);
        if ($className === null) {
            return null;
        }

        return $this->locateMethodInClass($className, $methodName->toString());
    }

    /**
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
     */
    private function handlePropertyDefinition(PropertyFetch $fetch, TextDocument $document): ?array
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveObjectType($fetch->var, $document);
        if ($className === null) {
            return null;
        }

        return $this->locatePropertyInClass($className, $propertyName->toString());
    }

    /**
     * Resolve the type of an expression (variable).
     */
    private function resolveObjectType(\PhpParser\Node\Expr $expr, TextDocument $document): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name) && $this->typeInference !== null) {
            $line = $expr->getStartLine();
            return $this->typeInference->getVariableType($document, $expr->name, $line);
        }

        return null;
    }

    /**
     * Locate a method definition in a class.
     *
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
     */
    private function locateMethodInClass(string $className, string $methodName): ?array
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
        $tempDoc = new TextDocument($uri, 'php', 0, $content);
        $ast = $this->parser->parse($tempDoc);
        if ($ast === null) {
            return null;
        }

        // Find the method in the class
        $methodLocation = $this->findMethodLocation($ast, $className, $methodName, $uri);
        if ($methodLocation !== null) {
            return $methodLocation->toLspLocation();
        }

        return null;
    }

    /**
     * Locate a property definition in a class.
     *
     * @return array{uri: string, range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}}|null
     */
    private function locatePropertyInClass(string $className, string $propertyName): ?array
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
        $tempDoc = new TextDocument($uri, 'php', 0, $content);
        $ast = $this->parser->parse($tempDoc);
        if ($ast === null) {
            return null;
        }

        // Find the property in the class
        $propertyLocation = $this->findPropertyLocation($ast, $className, $propertyName, $uri);
        if ($propertyLocation !== null) {
            return $propertyLocation->toLspLocation();
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findMethodLocation(array $ast, string $className, string $methodName, string $uri): ?Location
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $result = $this->findMethodLocation($stmt->stmts, $className, $methodName, $uri);
                if ($result !== null) {
                    return $result;
                }
            }

            if ($stmt instanceof Stmt\Class_ || $stmt instanceof Stmt\Interface_ || $stmt instanceof Stmt\Trait_) {
                $classShortName = $stmt->name?->toString();
                $namespacedName = $stmt->namespacedName;
                $fqn = $namespacedName instanceof Name ? $namespacedName->toString() : $classShortName;

                if ($fqn === $className || $classShortName === $className) {
                    foreach ($stmt->stmts as $member) {
                        if ($member instanceof Stmt\ClassMethod && $member->name->toString() === $methodName) {
                            return new Location(
                                $uri,
                                $member->getStartLine() - 1,
                                0,
                                $member->getEndLine() - 1,
                                0,
                            );
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findPropertyLocation(array $ast, string $className, string $propertyName, string $uri): ?Location
    {
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $result = $this->findPropertyLocation($stmt->stmts, $className, $propertyName, $uri);
                if ($result !== null) {
                    return $result;
                }
            }

            if ($stmt instanceof Stmt\Class_ || $stmt instanceof Stmt\Trait_) {
                $classShortName = $stmt->name?->toString();
                $namespacedName = $stmt->namespacedName;
                $fqn = $namespacedName instanceof Name ? $namespacedName->toString() : $classShortName;

                if ($fqn === $className || $classShortName === $className) {
                    foreach ($stmt->stmts as $member) {
                        // Traditional property declaration
                        if ($member instanceof Stmt\Property) {
                            foreach ($member->props as $prop) {
                                if ($prop->name->toString() === $propertyName) {
                                    return new Location(
                                        $uri,
                                        $member->getStartLine() - 1,
                                        0,
                                        $member->getEndLine() - 1,
                                        0,
                                    );
                                }
                            }
                        }

                        // Constructor-promoted properties
                        if ($member instanceof Stmt\ClassMethod && $member->name->toString() === '__construct') {
                            foreach ($member->params as $param) {
                                if ($param->flags !== 0) { // Has visibility modifier = promoted property
                                    $var = $param->var;
                                    if ($var instanceof Variable && is_string($var->name) && $var->name === $propertyName) {
                                        return new Location(
                                            $uri,
                                            $param->getStartLine() - 1,
                                            0,
                                            $param->getEndLine() - 1,
                                            0,
                                        );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return null;
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
}
