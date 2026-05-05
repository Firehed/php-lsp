<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;

/**
 * Centralizes symbol resolution for LSP handlers.
 *
 * This class provides a single entry point for resolving symbols at cursor
 * positions, eliminating the M×N problem where M handlers each independently
 * implement resolution logic for N node types.
 */
final class SymbolResolver
{
    public function __construct(
        private readonly ParserService $parser,
        private readonly ClassRepository $classRepository,
        private readonly MemberResolver $memberResolver,
        private readonly TypeResolverInterface $typeResolver,
    ) {
    }

    /**
     * Resolve symbol at cursor position.
     * Used by: Definition, Hover, TypeDefinition
     */
    public function resolveAtPosition(
        TextDocument $document,
        int $line,
        int $character,
    ): ?ResolvedSymbol {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('Parser returned null with error-collecting handler');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        return $this->resolveNode($node, $ast);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveNode(Node $node, array $ast): ?ResolvedSymbol
    {
        if ($node instanceof Identifier) {
            return $this->resolveIdentifier($node, $ast);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveIdentifier(Identifier $node, array $ast): ?ResolvedSymbol
    {
        $parent = $node->getAttribute('parent');

        // Instance method call: $obj->method() or $obj?->method()
        if (MemberAccessResolver::isMethodCall($parent)) {
            /** @var MethodCall|NullsafeMethodCall $parent */
            return $this->resolveMethodCall($parent, $ast);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveMethodCall(MethodCall|NullsafeMethodCall $call, array $ast): ?ResolvedSymbol
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $type = ExpressionTypeResolver::resolveExpressionType($call->var, $ast, $this->typeResolver);
        $classNames = $type?->getResolvableClassNames() ?? [];
        $className = $classNames[0] ?? null;

        if ($className === null) {
            return null;
        }

        $methodInfo = $this->memberResolver->findMethod(
            $className,
            new MethodName($methodName->toString()),
            Visibility::Private,
        );

        if ($methodInfo === null) {
            return null;
        }

        return new ResolvedMethod($methodInfo);
    }
}
