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
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use Firehed\PhpLsp\Domain\PropertyName;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
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
        // VarLikeIdentifier extends Identifier, so check it first
        if ($node instanceof VarLikeIdentifier) {
            return $this->resolveVarLikeIdentifier($node);
        }

        if ($node instanceof Identifier) {
            return $this->resolveIdentifier($node, $ast);
        }

        if ($node instanceof Name) {
            return $this->resolveName($node);
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

        // Static method call: ClassName::method()
        if ($parent instanceof StaticCall) {
            return $this->resolveStaticCall($parent);
        }

        // Property fetch: $obj->property or $obj?->property
        if (MemberAccessResolver::isPropertyFetch($parent)) {
            /** @var PropertyFetch|NullsafePropertyFetch $parent */
            return $this->resolvePropertyFetch($parent, $ast);
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

    private function resolveStaticCall(StaticCall $call): ?ResolvedSymbol
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $classNameStr = ScopeFinder::resolveClassNameInContext($class, $call);
        if ($classNameStr === null) {
            return null;
        }

        $methodInfo = $this->memberResolver->findMethod(
            new ClassName($classNameStr),
            new MethodName($methodName->toString()),
            Visibility::Private,
        );

        if ($methodInfo === null) {
            return null;
        }

        return new ResolvedMethod($methodInfo);
    }

    private function resolveName(Name $node): ?ResolvedSymbol
    {
        $classNameStr = ScopeFinder::resolveClassName($node);

        $classInfo = $this->classRepository->get(new ClassName($classNameStr));
        if ($classInfo === null) {
            return null;
        }

        return new ResolvedClass($classInfo);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolvePropertyFetch(PropertyFetch|NullsafePropertyFetch $fetch, array $ast): ?ResolvedSymbol
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $type = ExpressionTypeResolver::resolveExpressionType($fetch->var, $ast, $this->typeResolver);
        $classNames = $type?->getResolvableClassNames() ?? [];
        $className = $classNames[0] ?? null;

        if ($className === null) {
            return null;
        }

        $propertyInfo = $this->memberResolver->findProperty(
            $className,
            new PropertyName($propertyName->toString()),
            Visibility::Private,
        );

        if ($propertyInfo === null) {
            return null;
        }

        return new ResolvedProperty($propertyInfo);
    }

    private function resolveVarLikeIdentifier(VarLikeIdentifier $node): ?ResolvedSymbol
    {
        $parent = $node->getAttribute('parent');

        // Static property fetch: ClassName::$property
        if ($parent instanceof StaticPropertyFetch) {
            return $this->resolveStaticPropertyFetch($parent);
        }

        return null;
    }

    private function resolveStaticPropertyFetch(StaticPropertyFetch $fetch): ?ResolvedSymbol
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof VarLikeIdentifier) {
            return null;
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            return null;
        }

        $classNameStr = ScopeFinder::resolveClassNameInContext($class, $fetch);
        if ($classNameStr === null) {
            return null;
        }

        $propertyInfo = $this->memberResolver->findProperty(
            new ClassName($classNameStr),
            new PropertyName($propertyName->toString()),
            Visibility::Private,
        );

        if ($propertyInfo === null) {
            return null;
        }

        return new ResolvedProperty($propertyInfo);
    }
}
