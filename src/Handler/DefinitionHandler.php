<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\Location;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class DefinitionHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly MemberResolver $memberResolver,
        private readonly ClassRepository $classRepository,
        private readonly MemberAccessResolver $memberAccessResolver,
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
            // @codeCoverageIgnoreStart
            throw new \LogicException('Parser returned null with error-collecting handler');
            // @codeCoverageIgnoreEnd
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
                return $this->handleStaticMethodDefinition($parent);
            }

            // Instance method call: $obj->method() or $obj?->method()
            if (MemberAccessResolver::isMethodCall($parent)) {
                /** @var MethodCall|NullsafeMethodCall $parent */
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
        $symbolName = ScopeFinder::resolveClassName($node);

        $classInfo = $this->classRepository->get(new ClassName($symbolName));
        if ($classInfo === null) {
            return null;
        }

        return $this->createLocationFromFileLine($classInfo->file, $classInfo->line);
    }

    /**
     * Handle definition for static method call: ClassName::method()
     *
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function handleStaticMethodDefinition(StaticCall $call): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('handleStaticMethodDefinition called with non-Identifier method name');
            // @codeCoverageIgnoreEnd
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $rawName = $class->toString();

        // Handle parent:: - resolve to actual parent class name
        if ($rawName === 'parent') {
            $enclosingClass = ScopeFinder::findEnclosingClassNode($call);
            if (!$enclosingClass instanceof Stmt\Class_ || $enclosingClass->extends === null) {
                return null;
            }
            $className = ScopeFinder::resolveClassName($enclosingClass->extends);
        } elseif ($rawName === 'self' || $rawName === 'static') {
            // Handle self:: and static:: - resolve to enclosing class
            $className = ScopeFinder::findEnclosingClassName($call);
            if ($className === null) {
                return null;
            }
        } else {
            $className = ScopeFinder::resolveClassName($class);
        }

        return $this->findMethodDefinition($className, $methodName->toString());
    }

    /**
     * Handle definition for instance method call: $obj->method() or $obj?->method()
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
    private function handleInstanceMethodDefinition(MethodCall|NullsafeMethodCall $call, array $ast): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('handleInstanceMethodDefinition called with non-Identifier method name');
            // @codeCoverageIgnoreEnd
        }

        $className = $this->memberAccessResolver->resolveObjectClassName($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->findMethodDefinition($className->fqn, $methodName->toString());
    }

    /**
     * Find the definition of a method in a class.
     *
     * @param class-string $className
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }|null
     */
    private function findMethodDefinition(string $className, string $methodName): ?array
    {
        $methodInfo = $this->memberResolver->findMethod(
            new ClassName($className),
            new MethodName($methodName),
            Visibility::Private,
        );

        if ($methodInfo === null) {
            return null;
        }

        return $this->createLocationFromFileLine($methodInfo->file, $methodInfo->line);
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
    private function createLocationFromFileLine(?string $file, ?int $line): ?array
    {
        if ($file === null || $line === null) {
            return null;
        }

        $uri = str_starts_with($file, 'file://') ? $file : 'file://' . $file;
        $location = new Location($uri, $line - 1, 0, $line - 1, 0);

        return $location->toLspLocation();
    }
}
