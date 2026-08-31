<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\DocblockParser;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\ResolvedSymbol;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\VariableBinding;
use Firehed\PhpLsp\Utility\VariableBindings;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Clone_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\VarLikeIdentifier;

/**
 * Resolves any expression to a {@see ResolvedSymbol}; its value type is
 * `->getType()`.
 *
 * One entry point that hover, definition, member-access typing, and variable
 * completion share. Variable resolution reads {@see VariableBindings} directly
 * so JTD on `$var` lands on the nearest preceding binding node (#301).
 *
 * @internal
 */
final class ExpressionResolver
{
    public function __construct(
        private readonly MemberResolver $memberResolver,
        private readonly SymbolSource $symbolSource,
        private readonly TextDocument $document,
    ) {
    }

    /**
     * @param array<Stmt> $ast
     */
    public function resolve(Expr $expr, array $ast): ?ResolvedSymbol
    {
        $resolvedType = $expr->getAttribute('resolvedType');
        if ($resolvedType instanceof Type) {
            return new ResolvedTypeOnly($resolvedType);
        }

        if ($expr instanceof Variable && $expr->name === 'this') {
            $enclosing = ScopeFinder::findEnclosingClassName($expr);
            if ($enclosing === null) {
                return null;
            }
            return new ResolvedTypeOnly(TypeFactory::className($enclosing));
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            $scope = Scope::atOffset($ast, $expr->getStartFilePos());
            return $this->resolveVariable($expr->name, $scope, $expr->getStartFilePos(), $ast);
        }

        if ($expr instanceof New_) {
            return $this->resolveNew($expr);
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            return $this->resolveMethodCall($expr, $ast);
        }

        if ($expr instanceof StaticCall) {
            return $this->resolveStaticCall($expr);
        }

        if ($expr instanceof FuncCall) {
            return $this->resolveFuncCall($expr, $ast);
        }

        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            return $this->resolvePropertyFetch($expr, $ast);
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $this->resolveStaticPropertyFetch($expr);
        }

        if ($expr instanceof ClassConstFetch) {
            return $this->resolveClassConstFetch($expr);
        }

        if ($expr instanceof Expr\ConstFetch) {
            return $this->resolveConstFetch($expr);
        }

        if ($expr instanceof Clone_) {
            return $this->resolve($expr->expr, $ast);
        }

        if ($expr instanceof Ternary) {
            return $this->resolve($expr->if ?? $expr->cond, $ast)
                ?? $this->resolve($expr->else, $ast);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->resolve($expr->left, $ast) ?? $this->resolve($expr->right, $ast);
        }

        return null;
    }

    /**
     * Resolve `$name` referenced at $offset in $scope. Walks the innermost
     * scope's bindings; falls through only across arrow functions (implicit
     * capture). Long closures are isolated: an uncaptured name returns null
     * (#301).
     *
     * @param array<Stmt> $ast
     */
    public function resolveVariable(string $name, Scope $scope, int $offset, array $ast): ?ResolvedVariable
    {
        while (true) {
            $found = null;
            foreach (VariableBindings::before($scope, $offset) as $binding) {
                if ($binding->name === $name) {
                    $found = $binding;
                }
            }
            if ($found !== null) {
                return $this->resolveBinding($found, $scope, $ast);
            }
            if (!$scope->allowsImplicitCapture()) {
                return null;
            }
            $sourceNode = $scope->getSourceNode();
            assert($sourceNode !== null, 'allowsImplicitCapture() implies an ArrowFunction source node');
            $enclosingNode = ScopeFinder::findEnclosingScope($sourceNode);
            if ($enclosingNode === null) {
                return null;
            }
            $scope = Scope::forNode($enclosingNode);
            $offset = $sourceNode->getStartFilePos();
        }
    }

    /**
     * Resolve a known binding without re-walking the scope. Used by callers that
     * already have the binding in hand (variable enumeration), so an N-binding
     * scope costs N type resolutions instead of N² binding walks.
     *
     * @param array<Stmt> $ast
     */
    public function resolveBinding(VariableBinding $binding, Scope $scope, array $ast): ResolvedVariable
    {
        $type = $this->typeOfBinding($binding, $scope, $ast);
        $location = $this->locationFor($binding->node);
        return new ResolvedVariable($binding->name, $type, $location);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function typeOfBinding(VariableBinding $binding, Scope $scope, array $ast): ?Type
    {
        $node = $binding->node;
        assert($node instanceof Variable && is_string($node->name), 'VariableBindings yields named Variables only');
        $parent = $node->getAttribute('parent');

        if ($parent instanceof Expr\Assign && $parent->var === $node) {
            return $this->resolve($parent->expr, $ast)?->getType();
        }
        if ($parent instanceof Stmt\Foreach_) {
            return $this->foreachElementType($parent, $node, $ast);
        }
        if ($parent instanceof Stmt\Catch_) {
            assert($parent->types !== [], 'PHP grammar requires at least one type in a catch clause');
            $classNames = [];
            foreach ($parent->types as $type) {
                $classNames[] = TypeFactory::className(ScopeFinder::resolveClassName($type));
            }
            return count($classNames) === 1 ? $classNames[0] : TypeFactory::union($classNames);
        }
        if ($parent instanceof Node\ClosureUse) {
            $closure = $parent->getAttribute('parent');
            assert($closure instanceof Node, 'ParentConnectingVisitor sets ClosureUse->parent');
            $enclosingNode = ScopeFinder::findEnclosingScope($closure);
            if ($enclosingNode === null) {
                return null;
            }
            $outerScope = Scope::forNode($enclosingNode);
            return $this->resolveVariable($node->name, $outerScope, $closure->getStartFilePos(), $ast)?->getType();
        }
        assert($parent instanceof Param, 'VariableBindings parent kinds are exhausted above');
        return TypeFactory::fromNode($parent->type, $scope->getSelfContext(), $scope->getParentContext());
    }

    /**
     * @param array<Stmt> $ast
     */
    private function foreachElementType(Stmt\Foreach_ $foreach, Variable $bindingVar, array $ast): ?Type
    {
        if ($bindingVar === $foreach->keyVar) {
            return null;
        }
        $docblock = $this->docblockForExpression($foreach->expr, $ast);
        if ($docblock === null) {
            return null;
        }
        $elemShort = DocblockParser::arrayElementType($docblock);
        if ($elemShort === null) {
            return null;
        }
        return $this->resolveShortClassName($elemShort, $foreach->expr, $ast);
    }

    /**
     * The raw docblock of the value the expression produces, or null. Reads
     * the source info (MethodInfo / PropertyInfo / FunctionInfo) directly so
     * `@tag` lines survive — the description-only accessor would strip them.
     *
     * @param array<Stmt> $ast
     */
    private function docblockForExpression(Expr $expr, array $ast): ?string
    {
        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            $receiverType = $this->resolve($expr->var, $ast)?->getType();
            $classNames = $receiverType?->getResolvableClassNames() ?? [];
            if ($classNames === [] || !$expr->name instanceof Identifier) {
                return null;
            }
            $info = $this->memberResolver->findMethod(
                $classNames[0],
                new MethodName($expr->name->toString()),
                Visibility::Private,
            );
            return $info?->docblock;
        }
        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            $receiverType = $this->resolve($expr->var, $ast)?->getType();
            $classNames = $receiverType?->getResolvableClassNames() ?? [];
            if ($classNames === [] || !$expr->name instanceof Identifier) {
                return null;
            }
            $info = $this->memberResolver->findProperty(
                $classNames[0],
                new PropertyName($expr->name->toString()),
                Visibility::Private,
            );
            return $info?->docblock;
        }
        if ($expr instanceof FuncCall && $expr->name instanceof Name) {
            $context = NameContextFactory::fromAst($ast, $expr->name->getStartLine() - 1);
            foreach ($context->candidates($expr->name->toString(), NameKind::Function_) as $candidate) {
                $info = $this->symbolSource->lookupFunction(FunctionName::fromFullyQualified($candidate));
                if ($info !== null) {
                    return $info->docblock;
                }
            }
        }
        return null;
    }

    /**
     * Resolve a short name in the context of the calling file. Uses the file's
     * name context (namespace + imports) at the expression's line.
     *
     * @param array<Stmt> $ast
     */
    private function resolveShortClassName(string $shortOrFqn, Node $atNode, array $ast): ?Type
    {
        if (str_starts_with($shortOrFqn, '\\')) {
            $fqn = ltrim($shortOrFqn, '\\');
            /** @var class-string $fqn */
            return TypeFactory::className($fqn);
        }
        $context = NameContextFactory::fromAst($ast, $atNode->getStartLine() - 1);
        $candidates = $context->candidates($shortOrFqn, NameKind::ClassLike);
        foreach ($candidates as $candidate) {
            /** @var class-string $candidate */
            if ($this->symbolSource->lookupClassLike(TypeFactory::className($candidate)) !== null) {
                return TypeFactory::className($candidate);
            }
        }
        return null;
    }

    private function resolveNew(New_ $expr): ?ResolvedSymbol
    {
        if (!$expr->class instanceof Name) {
            return null;
        }
        $className = ScopeFinder::resolveClassNameInContext($expr->class, $expr);
        if ($className === null) {
            return null;
        }
        $classInfo = $this->symbolSource->lookupClassLike(TypeFactory::className($className));
        if ($classInfo === null) {
            return new ResolvedTypeOnly(TypeFactory::className($className));
        }
        return $classInfo;
    }

    /**
     * Resolve the constructor callable a `new X(...)` or `#[X(...)]` invokes.
     * Distinct from `resolve()` on the same node: `resolve(New_)` answers the
     * *type* the expression produces, while this answers the callable that
     * runs. Uses private visibility so promoted/private constructors are found.
     */
    public function resolveConstructor(New_|Attribute $call): ?MethodInfo
    {
        $classNameNode = $call instanceof New_ ? $call->class : $call->name;
        if (!$classNameNode instanceof Name) {
            return null;
        }
        $classNameStr = ScopeFinder::resolveClassNameInContext($classNameNode, $call);
        if ($classNameStr === null) {
            return null;
        }
        return $this->memberResolver->findMethod(
            TypeFactory::className($classNameStr),
            new MethodName('__construct'),
            Visibility::Private,
        );
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveMethodCall(MethodCall|NullsafeMethodCall $expr, array $ast): ?MethodInfo
    {
        if (!$expr->name instanceof Identifier) {
            return null;
        }
        $receiverType = $this->resolve($expr->var, $ast)?->getType();
        $classNames = $receiverType?->getResolvableClassNames() ?? [];
        if ($classNames === []) {
            return null;
        }
        $className = $classNames[0];
        $methodInfo = $this->memberResolver->findMethod(
            $className,
            new MethodName($expr->name->toString()),
            Visibility::Private,
        );
        if ($methodInfo === null) {
            return null;
        }
        return $this->resolveLateBoundReturn($methodInfo, $className);
    }

    private function resolveStaticCall(StaticCall $expr): ?MethodInfo
    {
        if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
            return null;
        }
        $classNameStr = ScopeFinder::resolveClassNameInContext($expr->class, $expr);
        if ($classNameStr === null) {
            return null;
        }
        $className = TypeFactory::className($classNameStr);
        $methodInfo = $this->memberResolver->findMethod(
            $className,
            new MethodName($expr->name->toString()),
            Visibility::Private,
        );
        if ($methodInfo === null) {
            return null;
        }
        return $this->resolveLateBoundReturn($methodInfo, $className);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFuncCall(FuncCall $expr, array $ast): ?FunctionInfo
    {
        if (!$expr->name instanceof Name) {
            return null;
        }
        $shortName = $expr->name->toString();
        $line = $expr->name->getStartLine() - 1;
        $context = NameContextFactory::fromAst($ast, $line);

        foreach ($context->candidates($shortName, NameKind::Function_) as $candidate) {
            $funcInfo = $this->symbolSource->lookupFunction(FunctionName::fromFullyQualified($candidate));
            if ($funcInfo !== null) {
                return $funcInfo;
            }
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolvePropertyFetch(PropertyFetch|NullsafePropertyFetch $expr, array $ast): ?PropertyInfo
    {
        if (!$expr->name instanceof Identifier) {
            return null;
        }
        $receiverType = $this->resolve($expr->var, $ast)?->getType();
        $classNames = $receiverType?->getResolvableClassNames() ?? [];
        if ($classNames === []) {
            return null;
        }
        $info = $this->memberResolver->findProperty(
            $classNames[0],
            new PropertyName($expr->name->toString()),
            Visibility::Private,
        );
        return $info;
    }

    private function resolveStaticPropertyFetch(StaticPropertyFetch $expr): ?PropertyInfo
    {
        if (!$expr->name instanceof VarLikeIdentifier || !$expr->class instanceof Name) {
            return null;
        }
        $classNameStr = ScopeFinder::resolveClassNameInContext($expr->class, $expr);
        if ($classNameStr === null) {
            return null;
        }
        $info = $this->memberResolver->findProperty(
            TypeFactory::className($classNameStr),
            new PropertyName($expr->name->toString()),
            Visibility::Private,
        );
        return $info;
    }

    private function resolveClassConstFetch(ClassConstFetch $expr): ?ResolvedSymbol
    {
        if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
            return null;
        }
        $classNameStr = ScopeFinder::resolveClassNameInContext($expr->class, $expr);
        if ($classNameStr === null) {
            return null;
        }
        $className = TypeFactory::className($classNameStr);
        $enumCase = $this->memberResolver->findEnumCase($className, new EnumCaseName($expr->name->toString()));
        if ($enumCase !== null) {
            return $enumCase;
        }
        $constant = $this->memberResolver->findConstant(
            $className,
            new ConstantName($expr->name->toString()),
            Visibility::Private,
        );
        return $constant;
    }

    private function resolveConstFetch(Expr\ConstFetch $expr): ?ConstantInfo
    {
        $name = ScopeFinder::resolveName($expr->name);
        $info = $this->symbolSource->lookupConstant(GlobalConstantName::fromFullyQualified($name));
        return $info;
    }

    private function resolveLateBoundReturn(MethodInfo $methodInfo, ClassName $callingClass): MethodInfo
    {
        $isFromTrait = $this->memberResolver->isTraitClass($methodInfo->declaringClass);
        $return = $methodInfo->returnType?->resolveLateBound($callingClass->fqn, $isFromTrait);
        if ($return === $methodInfo->returnType) {
            return $methodInfo;
        }
        return new MethodInfo(
            name: $methodInfo->name,
            visibility: $methodInfo->visibility,
            isStatic: $methodInfo->isStatic,
            isAbstract: $methodInfo->isAbstract,
            isFinal: $methodInfo->isFinal,
            parameters: $methodInfo->parameters,
            returnType: $return,
            declaringClass: $methodInfo->declaringClass,
            docblock: $methodInfo->docblock,
            file: $methodInfo->file,
            line: $methodInfo->line,
        );
    }

    private function locationFor(Node $node): Location
    {
        $start = $this->document->positionAt($node->getStartFilePos());
        return new Location(
            $this->document->uri,
            $start['line'],
            $start['character'],
            $start['line'],
            $start['character'],
        );
    }
}
