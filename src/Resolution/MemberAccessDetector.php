<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\NameCase;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\NodeAtPosition;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * Detects member-access context at a cursor position.
 *
 * Combines the AST path (a node walk) and the text path (regex primitives on
 * {@see TextFallbackHelper}) in one class. One {@see self::visibilityBetween()}
 * function decides the visibility a vantage class has toward a target class,
 * so the two paths cannot disagree.
 *
 * @internal
 */
final class MemberAccessDetector
{
    private readonly NodeAtPosition $nodeAtPosition;

    public function __construct(
        private readonly SymbolSource $symbolSource,
        private readonly MemberResolver $memberResolver,
        private readonly TextFallbackHelper $textFallback,
    ) {
        $this->nodeAtPosition = new NodeAtPosition();
    }

    private function expressionResolver(TextDocument $document): ExpressionResolver
    {
        return new ExpressionResolver($this->memberResolver, $this->symbolSource, $document->uri);
    }

    /**
     * @param array<Stmt> $ast
     */
    public function detect(
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $offset = $document->offsetAt($line, $character);

        $node = $this->nodeAtPosition->find($ast, $offset > 0 ? $offset - 1 : 0);

        if ($node === null) {
            return $this->fromText($document, $ast, $line, $character);
        }

        if ($node instanceof Identifier || $node instanceof Error) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node) {
                $node = $parent;
            } else {
                // @codeCoverageIgnoreStart
                throw new LogicException('Node missing parent attribute');
                // @codeCoverageIgnoreEnd
            }
        }

        if (self::isInstanceAccess($node)) {
            /** @var MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node */
            if (
                ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
                && $node->name instanceof Identifier
            ) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }

            $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
            $type = $this->resolveInstanceAccessType($node, $ast, $document, $line);
            $target = $type?->getResolvableClassNames()[0] ?? null;
            if ($type !== null && $target !== null) {
                $enclosingName = ScopeFinder::findEnclosingClassName($node);
                $vantage = $enclosingName !== null ? TypeFactory::className($enclosingName) : null;
                return MemberAccessContext::forInstance(
                    $type,
                    $this->visibilityBetween($vantage, $target),
                    $prefix,
                );
            }
            return $this->fromText($document, $ast, $line, $character);
        }

        if ($node instanceof StaticPropertyFetch || $node instanceof StaticCall || $node instanceof ClassConstFetch) {
            if ($node instanceof StaticCall && $node->name instanceof Identifier) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }
            return $this->resolveStaticAccessContext($node, $ast, $offset);
        }

        return $this->fromText($document, $ast, $line, $character);
    }

    /**
     * Resolve the type of the object in an instance member access, using the
     * AST first and falling back to the text-based enclosing class for `$this`
     * when the AST parses the receiver but cannot resolve its type.
     *
     * Public so callable resolution (method-call signature help / definition)
     * can share it with completion.
     *
     * @param array<Stmt> $ast
     */
    public function resolveInstanceAccessType(
        MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node,
        array $ast,
        ?TextDocument $document = null,
        ?int $line = null,
    ): ?Type {
        $resolvedType = $node->var->getAttribute('resolvedType');
        if ($resolvedType instanceof Type) {
            return $resolvedType;
        }

        $exprResolver = $document !== null ? $this->expressionResolver($document) : null;
        if ($exprResolver !== null) {
            $type = $exprResolver->resolve($node->var, $ast)?->getType();
            if ($type !== null) {
                return $type;
            }
        }

        if ($document === null || $line === null) {
            return null;
        }

        $thisVar = self::findThisVariable($node->var);
        if ($thisVar === null) {
            return null;
        }

        $offset = $document->offsetAt($line, 0);
        $enclosingClass = $this->textFallback->resolveEnclosingClassName(
            $ast,
            $offset,
            $document->getContent(),
            $line,
        );
        if ($enclosingClass === null) {
            return null;
        }

        $thisVar->setAttribute('resolvedType', TypeFactory::className($enclosingClass));
        return $this->expressionResolver($document)->resolve($node->var, $ast)?->getType();
    }

    /**
     * Convenience for callers that need the first resolvable class of an
     * instance access (method-call callable resolution).
     *
     * @param array<Stmt> $ast
     */
    public function resolveInstanceAccessClassName(
        MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node,
        array $ast,
        ?TextDocument $document = null,
    ): ?ClassName {
        $type = $this->resolveInstanceAccessType($node, $ast, $document);
        return $type?->getResolvableClassNames()[0] ?? null;
    }

    /**
     * The one function that decides how visible a target class is to a vantage
     * class. Same class: private. Subclass (any depth): protected. Otherwise
     * (or no vantage): public. Every call site — instance, static, `$this`,
     * `self::`, `parent::`, non-`$this` variable — routes through this
     * function, so the two paths cannot disagree on which members a position
     * may see.
     */
    private function visibilityBetween(?ClassName $vantage, ClassName $target): Visibility
    {
        if ($vantage === null) {
            return Visibility::Public;
        }
        if ($vantage->fqn === $target->fqn) {
            return Visibility::Private;
        }
        if ($this->symbolSource->isSubclassOf($vantage, $target)) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }

    /**
     * @param array<Stmt> $ast
     */
    public function fromText(
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $match = $this->textFallback->matchMemberAccessAt(
            $document->textBeforeCursor($line, $character),
        );
        if ($match === null) {
            return null;
        }

        $context = $this->resolveTextMatch($match, $document, $ast, $line);
        if ($context !== null) {
            return $context;
        }

        if ($match['kind'] === 'instance' && $match['var'] !== 'this') {
            return $this->resolveVariableAccessWithAst($match, $document, $ast, $line);
        }
        return null;
    }

    /**
     * @param array{kind: 'chain', chain: string, prefix: string}
     *      | array{kind: 'instance', var: string, prefix: string}
     *      | array{kind: 'static', class: string, prefix: string} $match
     * @param array<Stmt> $ast
     */
    private function resolveTextMatch(
        array $match,
        TextDocument $document,
        array $ast,
        int $line,
    ): ?MemberAccessContext {
        if ($match['kind'] === 'chain') {
            $enclosingClass = $this->textFallback->findEnclosingClass($document, $line);
            if ($enclosingClass === null) {
                return null;
            }
            $type = $this->walkChain($match['chain'], $enclosingClass);
            $target = $type?->getResolvableClassNames()[0] ?? null;
            if ($type === null || $target === null) {
                return null;
            }
            return MemberAccessContext::forInstance(
                $type,
                $this->visibilityBetween(TypeFactory::className($enclosingClass), $target),
                $match['prefix'],
            );
        }

        if ($match['kind'] === 'instance') {
            if ($match['var'] !== 'this') {
                return null;
            }
            $enclosingClass = $this->textFallback->findEnclosingClass($document, $line);
            if ($enclosingClass === null) {
                return null;
            }
            $target = TypeFactory::className($enclosingClass);
            return MemberAccessContext::forInstance(
                $target,
                $this->visibilityBetween($target, $target),
                $match['prefix'],
            );
        }

        return $this->resolveStaticText($match, $document, $ast, $line);
    }

    /**
     * @param array{kind: 'static', class: string, prefix: string} $match
     * @param array<Stmt> $ast
     */
    private function resolveStaticText(
        array $match,
        TextDocument $document,
        array $ast,
        int $line,
    ): ?MemberAccessContext {
        $className = $match['class'];
        $lowerClassName = NameCase::Insensitive->normalize($className);

        if ($lowerClassName === 'self' || $lowerClassName === 'static') {
            $enclosingClass = $this->textFallback->findEnclosingClass($document, $line);
            if ($enclosingClass === null) {
                return null;
            }
            $target = TypeFactory::className($enclosingClass);
            return MemberAccessContext::forStatic(
                $target,
                $this->visibilityBetween($target, $target),
                $match['prefix'],
            );
        }

        if ($lowerClassName === 'parent') {
            $offset = $document->offsetAt($line, 0);
            $classLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
            if (!$classLike instanceof Stmt\Class_) {
                return null;
            }
            $parentClassName = ScopeFinder::resolveExtendsName($classLike);
            $enclosingName = ScopeFinder::getClassLikeName($classLike);
            if ($parentClassName === null || $enclosingName === null) {
                return null;
            }
            $target = TypeFactory::className($parentClassName);
            return MemberAccessContext::forParent(
                $target,
                $this->visibilityBetween(TypeFactory::className($enclosingName), $target),
                $match['prefix'],
            );
        }

        $lines = explode("\n", $document->getContent());
        $context = NameContextFactory::fromAstOrText($ast, $line, $lines);
        $fqn = $context->candidates($className, NameKind::ClassLike)[0];

        // @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string)
        $target = TypeFactory::className($fqn);
        $enclosingClass = $this->textFallback->findEnclosingClass($document, $line);
        $vantage = $enclosingClass !== null ? TypeFactory::className($enclosingClass) : null;

        return MemberAccessContext::forStatic(
            $target,
            $this->visibilityBetween($vantage, $target),
            $match['prefix'],
        );
    }

    /**
     * @param array{kind: 'instance', var: string, prefix: string} $match
     * @param array<Stmt> $ast
     */
    private function resolveVariableAccessWithAst(
        array $match,
        TextDocument $document,
        array $ast,
        int $line,
    ): ?MemberAccessContext {
        $offset = $document->offsetAt($line, 0);
        $scope = Scope::atOffset($ast, $offset);

        $type = $this->expressionResolver($document)
            ->resolveVariable($match['var'], $scope, $offset, $ast)?->getType();

        if ($type === null) {
            $type = $this->resolveParameterTypeFromText($document, $ast, $line, $match['var']);
        }

        if ($type === null) {
            return null;
        }

        $target = $type->getResolvableClassNames()[0] ?? null;
        if ($target === null) {
            return null;
        }
        $enclosingClassName = $scope->getSelfContext();
        $vantage = $enclosingClassName !== null ? TypeFactory::className($enclosingClassName) : null;

        return MemberAccessContext::forInstance(
            $type,
            $this->visibilityBetween($vantage, $target),
            $match['prefix'],
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveParameterTypeFromText(
        TextDocument $document,
        array $ast,
        int $line,
        string $varName,
    ): ?Type {
        $lines = explode("\n", $document->getContent());
        $rawType = $this->textFallback->matchParameterType($lines, $line, $varName);
        if ($rawType === null) {
            return null;
        }

        $context = NameContextFactory::fromAstOrText($ast, $line, $lines);
        $classTypes = [];
        foreach (explode('|', $rawType) as $part) {
            $part = ltrim($part, '?');
            if ($part === '' || in_array(NameCase::Insensitive->normalize($part), PrimitiveType::NAMES, true)) {
                continue;
            }
            $fqn = $context->candidates($part, NameKind::ClassLike)[0];
            /** @var class-string $fqn */
            $classTypes[] = TypeFactory::className($fqn);
        }

        if ($classTypes === []) {
            return null;
        }
        return TypeFactory::union($classTypes);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveStaticAccessContext(
        StaticPropertyFetch|StaticCall|ClassConstFetch $node,
        array $ast,
        int $offset,
    ): ?MemberAccessContext {
        $class = $node->class;
        if (!$class instanceof Name) {
            return null;
        }

        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
        $rawName = $class->toString();
        $enclosingClassLike = Scope::atOffset($ast, $offset)->getEnclosingClassLike();
        $enclosingName = $enclosingClassLike !== null
            ? ScopeFinder::getClassLikeName($enclosingClassLike)
            : null;
        $vantage = $enclosingName !== null ? TypeFactory::className($enclosingName) : null;

        if ($rawName === 'parent') {
            if (!$enclosingClassLike instanceof Stmt\Class_ || $enclosingClassLike->extends === null) {
                return null;
            }
            $parentClassName = ScopeFinder::resolveExtendsName($enclosingClassLike);
            assert($parentClassName !== null);
            $target = TypeFactory::className($parentClassName);
            return MemberAccessContext::forParent(
                $target,
                $this->visibilityBetween($vantage, $target),
                $prefix,
            );
        }

        $className = ScopeFinder::resolveClassNameInContext($class, $node);
        if ($className === null) {
            // @codeCoverageIgnoreStart
            // self::/static:: outside a class - parser error recovery makes this hard to reach
            return null;
            // @codeCoverageIgnoreEnd
        }

        $target = TypeFactory::className($className);
        return MemberAccessContext::forStatic(
            $target,
            $this->visibilityBetween($vantage, $target),
            $prefix,
        );
    }

    private static function isInstanceAccess(Node $node): bool
    {
        return $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof PropertyFetch
            || $node instanceof NullsafePropertyFetch;
    }

    /**
     * Walk `$this->foo->bar()->...` against the type graph. The `$this->` prefix
     * is trimmed; regex-splitting is delegated to TextFallbackHelper.
     *
     * @param class-string $thisClass
     */
    private function walkChain(string $chainExpr, string $thisClass): ?Type
    {
        if (!str_starts_with($chainExpr, '$this->')) {
            // @codeCoverageIgnoreStart
            throw new LogicException('walkChain called without $this-> prefix');
            // @codeCoverageIgnoreEnd
        }

        $parts = $this->textFallback->splitChainParts(substr($chainExpr, 7));
        $currentType = TypeFactory::className($thisClass);
        $isFirstPart = true;

        foreach ($parts as $part) {
            $classNames = $currentType->getResolvableClassNames();
            if ($classNames === []) {
                return null;
            }
            $visibility = $isFirstPart ? Visibility::Private : Visibility::Public;
            $isFirstPart = false;

            if ($part['isMethodCall']) {
                $method = $this->memberResolver->findMethod(
                    $classNames[0],
                    new \Firehed\PhpLsp\Domain\MethodName($part['name']),
                    $visibility,
                );
                if ($method === null) {
                    return null;
                }
                $next = $method->returnType;
            } else {
                $property = $this->memberResolver->findProperty(
                    $classNames[0],
                    new \Firehed\PhpLsp\Domain\PropertyName($part['name']),
                    $visibility,
                );
                if ($property === null) {
                    return null;
                }
                $next = $property->type;
            }
            if ($next === null) {
                return null;
            }
            $currentType = $next;
        }
        return $currentType;
    }

    private static function findThisVariable(Node\Expr $expr): ?Variable
    {
        if ($expr instanceof Variable && $expr->name === 'this') {
            return $expr;
        }
        if (
            $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch
            || $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall
        ) {
            return self::findThisVariable($expr->var);
        }
        return null;
    }
}
