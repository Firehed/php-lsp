<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\DocblockParser;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
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
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Resolves any expression to a {@see ResolvedSymbolInterface}; its value type is
 * `->getType()`.
 *
 * One entry point that hover, definition, member-access typing, and variable
 * completion share. Variable resolution reads {@see VariableBindings} directly
 * so JTD on `$var` lands on the nearest preceding binding node (#301). What
 * encloses an expression is its {@see Scope}.
 */
final class ExpressionResolver implements ExpressionResolverInterface
{
    public function __construct(
        private readonly MemberResolverInterface $memberResolver,
        private readonly SymbolSourceInterface $symbolSource,
        private readonly TypeSourceInterface $typeSource,
    ) {
    }

    public function resolve(Expr $expr, ParsedDocument $parsed): ?ResolvedSymbolInterface
    {
        if ($expr instanceof Variable && $expr->name === 'this') {
            $type = Scope::at($expr, $parsed->tree)->getThisType();
            return $type === null ? null : new ResolvedTypeOnly(new ClasslikeType($type));
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            $scope = Scope::at($expr, $parsed->tree);
            return $this->resolveVariable($expr->name, $scope, $expr->getStartFilePos(), $parsed);
        }

        if ($expr instanceof New_) {
            return $this->resolveNew($expr, $parsed);
        }

        if ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            return $this->resolveMethodCall($expr, $parsed);
        }

        if ($expr instanceof StaticCall) {
            return $this->resolveStaticCall($expr, $parsed);
        }

        if ($expr instanceof FuncCall) {
            return $this->resolveFuncCall($expr, $parsed);
        }

        if ($expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch) {
            return $this->resolvePropertyFetch($expr, $parsed);
        }

        if ($expr instanceof StaticPropertyFetch) {
            return $this->resolveStaticPropertyFetch($expr, $parsed);
        }

        if ($expr instanceof ClassConstFetch) {
            return $this->resolveClassConstFetch($expr, $parsed);
        }

        if ($expr instanceof Expr\ConstFetch) {
            return $this->resolveConstFetch($expr, $parsed);
        }

        if ($expr instanceof Clone_) {
            return $this->resolve($expr->expr, $parsed);
        }

        if ($expr instanceof Ternary) {
            return $this->resolve($expr->if ?? $expr->cond, $parsed)
                ?? $this->resolve($expr->else, $parsed);
        }

        if ($expr instanceof Expr\BinaryOp\Coalesce) {
            return $this->resolve($expr->left, $parsed) ?? $this->resolve($expr->right, $parsed);
        }

        return null;
    }

    /**
     * Resolve `$name` referenced at $offset in $scope: the nearest binding
     * {@see VariableBindings::visible()} there. Long closures are isolated: an
     * uncaptured name returns null (#301).
     */
    public function resolveVariable(string $name, Scope $scope, int $offset, ParsedDocument $parsed): ?ResolvedVariable
    {
        $binding = VariableBindings::visible($scope, $offset)[$name] ?? null;
        return $binding === null ? null : $this->resolveBinding($binding, $parsed);
    }

    /**
     * Resolve a known binding without re-walking the scope. Used by callers that
     * already have the binding in hand (variable enumeration), so an N-binding
     * scope costs N type resolutions instead of N² binding walks.
     */
    public function resolveBinding(VariableBinding $binding, ParsedDocument $parsed): ResolvedVariable
    {
        $type = $this->typeOfBinding($binding, $parsed);
        $location = self::locationFor($binding->node, $parsed->document);
        return new ResolvedVariable($binding->name, $type, $location);
    }

    public function memberTarget(Expr|Name $receiver, ParsedDocument $parsed): ?MemberTarget
    {
        $scope = Scope::at($receiver, $parsed->tree);
        if ($receiver instanceof Name) {
            $className = $scope->resolveClasslikeName($receiver);
            return $className === null ? null : new MemberTarget(
                new ClasslikeType($className),
                $scope->getSelfName(),
                $scope->staticFor($receiver),
            );
        }
        $type = $this->resolve($receiver, $parsed)?->getType();
        if ($type === null || $type->getResolvableClasslikeNames() === []) {
            return null;
        }
        return new MemberTarget($type, $scope->getSelfName(), null);
    }

    public function resolveParameter(Param $param, ParsedDocument $parsed): ParameterInfo
    {
        assert($param->var instanceof Variable && is_string($param->var->name), 'a declared parameter is named');
        $scope = Scope::at($param, $parsed->tree);
        $function = $scope->getSourceNode();
        assert($function !== null, 'a parameter is declared by a function-like');
        $position = array_search($param, $function->params, true);
        assert(is_int($position), 'a parameter is among those of the function-like declaring it');

        return new ParameterInfo(
            name: $param->var->name,
            type: ParameterTyping::resolve($this->typeSource, $param, $param->var->name, $scope),
            defaultValue: $param->default === null ? null : (new PrettyPrinter())->prettyPrintExpr($param->default),
            position: $position,
            isVariadic: $param->variadic,
            isPassedByReference: $param->byRef,
        );
    }

    private function typeOfBinding(VariableBinding $binding, ParsedDocument $parsed): ?TypeInterface
    {
        $scope = $binding->scope;
        $node = $binding->node;
        assert($node instanceof Variable && is_string($node->name), 'VariableBindings yields named Variables only');
        $parent = $node->getAttribute('parent');

        if ($parent instanceof Expr\Assign && $parent->var === $node) {
            return $this->resolve($parent->expr, $parsed)?->getType();
        }
        if ($parent instanceof Stmt\Foreach_) {
            return $this->foreachElementType($parent, $node, $parsed);
        }
        if ($parent instanceof Stmt\Catch_) {
            assert($parent->types !== [], 'PHP grammar requires at least one type in a catch clause');
            $classTypes = [];
            foreach ($parent->types as $type) {
                $name = ClasslikeName::fromFullyQualified($type->toString());
                $classTypes[] = new ClasslikeType($name);
            }
            return count($classTypes) === 1 ? $classTypes[0] : new UnionType($classTypes);
        }
        if ($parent instanceof Node\ClosureUse) {
            $closure = $scope->getSourceNode();
            $outer = $scope->outer();
            assert($closure !== null && $outer !== null, 'a use clause binds in the scope of its closure');
            return $this->resolveVariable($node->name, $outer, $closure->getStartFilePos(), $parsed)?->getType();
        }
        assert($parent instanceof Param, 'VariableBindings parent kinds are exhausted above');
        return ParameterTyping::resolve($this->typeSource, $parent, $node->name, $scope);
    }

    private function foreachElementType(
        Stmt\Foreach_ $foreach,
        Variable $bindingVar,
        ParsedDocument $parsed,
    ): ?TypeInterface {
        if ($bindingVar === $foreach->keyVar) {
            return null;
        }
        $docblock = $this->resolve($foreach->expr, $parsed)?->getDocumentation();
        if ($docblock === null) {
            return null;
        }
        $elemShort = DocblockParser::arrayElementType($docblock);
        if ($elemShort === null) {
            return null;
        }
        return $this->resolveShortClasslikeName($elemShort, $foreach->expr, $parsed);
    }

    /**
     * Resolve a short name in the context of the calling file. Uses the file's
     * name context (namespace + imports) at the expression's line.
     */
    private function resolveShortClasslikeName(string $shortOrFqn, Node $atNode, ParsedDocument $parsed): ?TypeInterface
    {
        if (str_starts_with($shortOrFqn, '\\')) {
            return new ClasslikeType(ClasslikeName::fromFullyQualified($shortOrFqn));
        }
        $candidates = Scope::at($atNode, $parsed->tree)->nameContext()->candidates($shortOrFqn, NameKind::ClassLike);
        foreach ($candidates as $candidate) {
            /** @var class-string $candidate */
            $name = ClasslikeName::fromFullyQualified($candidate);
            if ($this->symbolSource->lookupClassLike($name) !== null) {
                return new ClasslikeType($name);
            }
        }
        return null;
    }

    private function resolveNew(New_ $expr, ParsedDocument $parsed): ?ResolvedSymbolInterface
    {
        if (!$expr->class instanceof Name) {
            return null;
        }
        $name = Scope::at($expr, $parsed->tree)->resolveClasslikeName($expr->class);
        if ($name === null) {
            return null;
        }
        $classInfo = $this->symbolSource->lookupClassLike($name);
        if ($classInfo === null) {
            return new ResolvedTypeOnly(new ClasslikeType($name));
        }
        return $classInfo;
    }

    /**
     * The one entry point every callable-shaped node resolves through, so a
     * caller does not branch on node kind. `New_`
     * and `Attribute` are constructor invocations and route to
     * `resolveConstructor`; the other four are ordinary expressions whose
     * resolved symbol is already a callable.
     */
    public function resolveCallable(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $call,
        ParsedDocument $parsed,
    ): ?ResolvedCallableInterface {
        if ($call instanceof New_ || $call instanceof Attribute) {
            return $this->resolveConstructor($call, $parsed);
        }
        $symbol = $this->resolve($call, $parsed);
        return $symbol instanceof ResolvedCallableInterface ? $symbol : null;
    }

    /**
     * Resolve the constructor callable a `new X(...)` or `#[X(...)]` invokes.
     * Distinct from `resolve()` on the same node: `resolve(New_)` answers the
     * *type* the expression produces, while this answers the callable that
     * runs.
     */
    private function resolveConstructor(New_|Attribute $call, ParsedDocument $parsed): ?MethodInfo
    {
        $classNameNode = $call instanceof New_ ? $call->class : $call->name;
        if (!$classNameNode instanceof Name) {
            return null;
        }
        return $this->resolveMember(
            $classNameNode,
            '__construct',
            $this->memberResolver->findMethod(...),
            $parsed,
        );
    }

    private function resolveMethodCall(MethodCall|NullsafeMethodCall $expr, ParsedDocument $parsed): ?MethodInfo
    {
        if (!$expr->name instanceof Identifier) {
            return null;
        }
        return $this->resolveMember(
            $expr->var,
            $expr->name->toString(),
            $this->memberResolver->findMethod(...),
            $parsed,
        );
    }

    private function resolveStaticCall(StaticCall $expr, ParsedDocument $parsed): ?MethodInfo
    {
        if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
            return null;
        }
        return $this->resolveMember(
            $expr->class,
            $expr->name->toString(),
            $this->memberResolver->findMethod(...),
            $parsed,
        );
    }

    private function resolveFuncCall(FuncCall $expr, ParsedDocument $parsed): ?FunctionInfo
    {
        if (!$expr->name instanceof Name) {
            return null;
        }
        // `toCodeString()` preserves the leading `\` for a fully-qualified name,
        // which the php-parser NameResolver may have produced when resolving an
        // imported function; `NameContext::candidates` reads that as the caller
        // meaning exactly this FQN, no namespace prepend.
        $shortName = $expr->name->toCodeString();
        $context = Scope::at($expr, $parsed->tree)->nameContext();

        foreach ($context->candidates($shortName, NameKind::Function_) as $candidate) {
            $funcInfo = $this->symbolSource->lookupFunction(FunctionName::fromFullyQualified($candidate));
            if ($funcInfo !== null) {
                return $funcInfo;
            }
        }
        return null;
    }

    private function resolvePropertyFetch(
        PropertyFetch|NullsafePropertyFetch $expr,
        ParsedDocument $parsed,
    ): ?PropertyInfo {
        if (!$expr->name instanceof Identifier) {
            return null;
        }
        return $this->resolveMember(
            $expr->var,
            $expr->name->toString(),
            $this->memberResolver->findProperty(...),
            $parsed,
        );
    }

    private function resolveStaticPropertyFetch(StaticPropertyFetch $expr, ParsedDocument $parsed): ?PropertyInfo
    {
        if (!$expr->name instanceof VarLikeIdentifier || !$expr->class instanceof Name) {
            return null;
        }
        return $this->resolveMember(
            $expr->class,
            $expr->name->toString(),
            $this->memberResolver->findProperty(...),
            $parsed,
        );
    }

    /**
     * Access a member on the receiver, delegating the kind-specific lookup to
     * `$find`. Only a member visible from where the access is written is found.
     *
     * @template T of MemberInfoInterface
     * @param callable(ClasslikeName, string, ?ClasslikeName, ?ClasslikeName): ?T $find
     * @return ?T
     */
    private function resolveMember(
        Expr|Name $receiver,
        string $memberName,
        callable $find,
        ParsedDocument $parsed,
    ): ?MemberInfoInterface {
        $target = $this->memberTarget($receiver, $parsed);
        if ($target === null) {
            return null;
        }
        foreach ($target->type->getResolvableClasslikeNames() as $className) {
            $member = $find($className, $memberName, $target->from, $target->static);
            if ($member !== null) {
                return $member;
            }
        }
        return null;
    }

    private function resolveClassConstFetch(ClassConstFetch $expr, ParsedDocument $parsed): ?ResolvedSymbolInterface
    {
        if (!$expr->name instanceof Identifier || !$expr->class instanceof Name) {
            return null;
        }
        return $this->resolveMember(
            $expr->class,
            $expr->name->toString(),
            fn (ClasslikeName $class, string $name, ?ClasslikeName $from, ?ClasslikeName $static)
                => $this->memberResolver->findEnumCase($class, $name)
                    ?? $this->memberResolver->findConstant($class, $name, $from, $static),
            $parsed,
        );
    }

    private function resolveConstFetch(Expr\ConstFetch $expr, ParsedDocument $parsed): ?ConstantInfo
    {
        // See resolveFuncCall for why toCodeString(): the same NameResolver may
        // have already turned `\Foo\BAR` or an imported constant into a
        // FullyQualified name.
        $shortName = $expr->name->toCodeString();
        $context = Scope::at($expr, $parsed->tree)->nameContext();

        foreach ($context->candidates($shortName, NameKind::Constant) as $candidate) {
            $info = $this->symbolSource->lookupConstant(ConstantName::fromFullyQualified($candidate));
            if ($info !== null) {
                return $info;
            }
        }
        return null;
    }

    private static function locationFor(Node $node, TextDocument $document): Location
    {
        $start = $document->positionAt($node->getStartFilePos());
        return new Location(
            $document->uri,
            $start['line'],
            $start['character'],
            $start['line'],
            $start['character'],
        );
    }
}
