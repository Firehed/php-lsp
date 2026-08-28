<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Domain\NameCase;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
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
    public function __construct(
        private readonly SymbolSource $symbolSource,
        private readonly TypeResolverInterface $typeResolver,
        private readonly TextFallbackHelper $textFallback,
    ) {
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

        $node = (new \Firehed\PhpLsp\Utility\NodeAtPosition())
            ->find($ast, $offset > 0 ? $offset - 1 : 0);

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
            if ($type !== null) {
                $vantage = self::enclosingClassNameOf($node);
                $target = $type->getResolvableClassNames()[0] ?? null;
                $isThis = $node->var instanceof Variable && $node->var->name === 'this';
                $visibility = $isThis
                    ? Visibility::Private
                    : ($target !== null ? $this->visibilityBetween($vantage, $target) : Visibility::Public);

                return MemberAccessContext::forInstance($type, $visibility, $prefix);
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

        $type = ExpressionTypeResolver::resolveExpressionType($node->var, $ast, $this->typeResolver);
        if ($type !== null) {
            return $type;
        }

        if ($document !== null && $line !== null && self::expressionStartsWithThis($node->var)) {
            $offset = $document->offsetAt($line, 0);
            $content = $document->getContent();
            $enclosingClass = $this->textFallback->resolveEnclosingClassName($ast, $offset, $content, $line);
            if ($enclosingClass !== null) {
                $thisVar = self::findThisVariable($node->var);
                if ($thisVar !== null) {
                    $thisVar->setAttribute('resolvedType', TypeFactory::className($enclosingClass));
                    return ExpressionTypeResolver::resolveExpressionType(
                        $node->var,
                        $ast,
                        $this->typeResolver,
                    );
                }
            }
        }

        return null;
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
    ): ?ClassName {
        $type = $this->resolveInstanceAccessType($node, $ast);
        return $type?->getResolvableClassNames()[0] ?? null;
    }

    /**
     * The one function that decides how visible a target class is to a vantage
     * class. Same class: private. Subclass (any depth): protected. Otherwise
     * (or no vantage): public.
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
        $textBeforeCursor = $document->textBeforeCursor($line, $character);
        $match = $this->textFallback->matchMemberAccessAt($textBeforeCursor);

        if ($match !== null) {
            $context = $this->resolveTextMatch($match, $document, $ast, $line);
            if ($context !== null) {
                return $context;
            }
        }

        return $this->resolveVariableAccessWithAst($document, $ast, $line, $character);
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
        $enclosingClass = $this->textFallback->findEnclosingClass($document, $line);

        if ($match['kind'] === 'chain') {
            if ($enclosingClass === null) {
                return null;
            }
            $type = $this->textFallback->resolveChainType($match['chain'], $enclosingClass);
            if ($type === null) {
                return null;
            }
            $target = $type->getResolvableClassNames()[0] ?? null;
            $vantage = TypeFactory::className($enclosingClass);
            $visibility = $target !== null
                ? $this->visibilityBetween($vantage, $target)
                : Visibility::Public;
            return MemberAccessContext::forInstance($type, $visibility, $match['prefix']);
        }

        if ($match['kind'] === 'instance') {
            if ($match['var'] !== 'this') {
                return null;
            }
            if ($enclosingClass === null) {
                return null;
            }
            return MemberAccessContext::forInstance(
                TypeFactory::className($enclosingClass),
                Visibility::Private,
                $match['prefix'],
            );
        }

        return $this->resolveStaticText($match, $document, $ast, $line, $enclosingClass);
    }

    /**
     * @param array{kind: 'static', class: string, prefix: string} $match
     * @param array<Stmt> $ast
     * @param class-string|null $enclosingClass
     */
    private function resolveStaticText(
        array $match,
        TextDocument $document,
        array $ast,
        int $line,
        ?string $enclosingClass,
    ): ?MemberAccessContext {
        $className = $match['class'];
        $lowerClassName = NameCase::Insensitive->normalize($className);

        if ($lowerClassName === 'self' || $lowerClassName === 'static') {
            if ($enclosingClass === null) {
                return null;
            }
            return MemberAccessContext::forStatic(
                TypeFactory::className($enclosingClass),
                Visibility::Private,
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
            if ($parentClassName === null) {
                return null;
            }
            return MemberAccessContext::forParent(
                TypeFactory::className($parentClassName),
                Visibility::Protected,
                $match['prefix'],
            );
        }

        $lines = explode("\n", $document->getContent());
        $context = NameContextFactory::fromAstOrText($ast, $line, $lines);
        $fqn = $context->candidates($className, NameKind::ClassLike)[0];

        // @phpstan-ignore argument.type (text-based resolution cannot guarantee class-string)
        $target = TypeFactory::className($fqn);
        $vantage = $enclosingClass !== null ? TypeFactory::className($enclosingClass) : null;

        return MemberAccessContext::forStatic(
            $target,
            $this->visibilityBetween($vantage, $target),
            $match['prefix'],
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveVariableAccessWithAst(
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $match = $this->textFallback->matchMemberAccessAt(
            $document->textBeforeCursor($line, $character),
        );
        if ($match === null || $match['kind'] !== 'instance' || $match['var'] === 'this') {
            return null;
        }

        $offset = $document->offsetAt($line, 0);
        $scope = Scope::atOffset($ast, $offset);

        $type = $this->typeResolver->resolveVariableType($match['var'], $scope, $line, $ast);

        if ($type === null) {
            $type = $this->resolveParameterTypeFromText($document, $ast, $line, $match['var']);
        }

        if ($type === null) {
            return null;
        }

        $enclosingClassName = $scope->getSelfContext();
        $vantage = $enclosingClassName !== null ? TypeFactory::className($enclosingClassName) : null;
        $target = $type->getResolvableClassNames()[0] ?? null;
        $visibility = $target !== null
            ? $this->visibilityBetween($vantage, $target)
            : Visibility::Public;

        return MemberAccessContext::forInstance($type, $visibility, $match['prefix']);
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

        if ($rawName === 'parent') {
            if (!$enclosingClassLike instanceof Stmt\Class_ || $enclosingClassLike->extends === null) {
                return null;
            }
            $parentClassName = ScopeFinder::resolveExtendsName($enclosingClassLike);
            assert($parentClassName !== null);
            return MemberAccessContext::forParent(
                TypeFactory::className($parentClassName),
                Visibility::Protected,
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
        $vantage = $enclosingClassLike !== null
            ? self::classNameOfClassLike($enclosingClassLike)
            : null;

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

    private static function enclosingClassNameOf(Node $node): ?ClassName
    {
        $name = ScopeFinder::findEnclosingClassName($node);
        return $name !== null ? TypeFactory::className($name) : null;
    }

    private static function classNameOfClassLike(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node,
    ): ?ClassName {
        $name = ScopeFinder::getClassLikeName($node);
        return $name !== null ? TypeFactory::className($name) : null;
    }

    private static function expressionStartsWithThis(Node\Expr $expr): bool
    {
        if ($expr instanceof Variable && $expr->name === 'this') {
            return true;
        }
        if (
            $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch
            || $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall
        ) {
            return self::expressionStartsWithThis($expr->var);
        }
        return false;
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
        // @codeCoverageIgnoreStart
        throw new LogicException('findThisVariable called with unhandled expression type');
        // @codeCoverageIgnoreEnd
    }
}
