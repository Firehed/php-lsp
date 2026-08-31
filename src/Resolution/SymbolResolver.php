<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\ResolvedCallable;
use Firehed\PhpLsp\Domain\ResolvedMember;
use Firehed\PhpLsp\Domain\ResolvedSymbol;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\Utility\NodeAtPosition;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\VariableBindings;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\VarLikeIdentifier;
use Throwable;

/**
 * Centralizes symbol resolution for LSP handlers.
 *
 * This class provides a single entry point for resolving symbols at cursor
 * positions, eliminating the M×N problem where M handlers each independently
 * implement resolution logic for N node types.
 *
 * FUTURE: Workspace queries (requires index)
 * - findReferences(SymbolIdentity $symbol, ?Scope $scope = null): array<Location>
 * - findImplementations(ClassName $interface): array<ClassInfo>
 * - findSubtypes(ClassName $class): array<ClassInfo>
 * - findSupertypes(ClassName $class): array<ClassInfo>
 *
 * FUTURE: Call hierarchy
 * - getIncomingCalls(ResolvedCallable $callable): array<CallHierarchyItem>
 * - getOutgoingCalls(ResolvedCallable $callable): array<CallHierarchyItem>
 *
 * FUTURE: Batch operations (for SemanticTokens)
 * - resolveAllSymbols(Document $document): array<ResolvedToken>
 */
final class SymbolResolver implements CodeResolver
{
    private readonly TextFallbackHelper $textFallback;
    private readonly CallContextDetector $callDetector;
    private readonly MemberAccessDetector $memberAccessDetector;

    public function __construct(
        private readonly ParserService $parser,
        private readonly SymbolSource $symbolSource,
        private readonly MemberResolver $memberResolver,
    ) {
        $this->textFallback = new TextFallbackHelper();
        $this->callDetector = new CallContextDetector($this->textFallback);
        $this->memberAccessDetector = new MemberAccessDetector(
            $symbolSource,
            $memberResolver,
            $this->textFallback,
            $parser,
        );
    }

    private function expressionResolver(TextDocument $document): ExpressionResolver
    {
        return new ExpressionResolver($this->memberResolver, $this->symbolSource, $document);
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
            throw new LogicException('Parser returned null with error-collecting handler');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        return $this->resolveNode($node, $ast, $document);
    }

    /**
     * Get members accessible on a type.
     * Used by: Completion (after -> or ::)
     *
     * For instance access: returns methods and properties.
     * For static access: also includes constants and enum cases.
     *
     * @return list<ResolvedMember>
     */
    public function getAccessibleMembers(
        TextDocument $document,
        Type $type,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::Instance,
    ): array {
        $classNames = $type->getResolvableClassNames();
        if ($classNames === []) {
            return [];
        }

        $members = [];
        $includeStatic = $filter !== MemberFilter::Instance;

        foreach ($classNames as $className) {
            foreach ($this->getMembersForClass($className, $minVisibility, $filter, $includeStatic) as $member) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * Get members for a single class using AST/reflection.
     *
     * One loop over the kinds a position admits, so no kind can drift onto a
     * different walk. The *Info metadata objects implement {@see ResolvedMember}
     * directly, so no wrapper is built per member.
     *
     * @return list<ResolvedMember>
     */
    private function getMembersForClass(
        ClassName $className,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $includeStatic,
    ): array {
        $kinds = $includeStatic
            ? [MemberKind::Method, MemberKind::Property, MemberKind::Constant, MemberKind::EnumCase]
            : [MemberKind::Method, MemberKind::Property];

        $members = [];
        foreach ($kinds as $kind) {
            foreach ($this->memberResolver->getMembersOfKind($className, $kind, $minVisibility, $filter) as $member) {
                $members[] = $member;
            }
        }
        return $members;
    }

    /**
     * Check if a name resolves to a real class-like.
     * Returns false for unknown names: a phantom must be dropped, so this cannot
     * share the optimism of the position predicates.
     */
    public function isClassLike(ClassName $className): bool
    {
        return $this->symbolSource->lookupClassLike($className) !== null;
    }

    /**
     * Check if a class can be instantiated with `new`.
     * Returns true for unknown classes (optimistic filtering).
     */
    public function isInstantiable(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return true;
        }
        return !$classInfo->isAbstract && $classInfo->isClass();
    }

    public function isValidTypeHint(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return true;
        }
        return !$classInfo->isTrait();
    }

    /**
     * Check if a class-like is an interface.
     * Returns false for unknown classes: an implements list must only offer
     * confirmed interfaces.
     */
    public function isInterface(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isInterface();
    }

    public function isTrait(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isTrait();
    }

    /**
     * Check if a class-like can be extended by a class.
     * True for non-final classes (abstract included); false for final classes,
     * interfaces, traits, enums, and unknown classes: an extends clause must
     * only offer confirmed base classes.
     */
    public function isExtendableClass(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isClass() && !$classInfo->isFinal;
    }

    /**
     * Check if a class-like can be caught.
     * True for `Throwable` itself and anything that extends or implements it
     * transitively (classes and interfaces alike). Returns false for unknown
     * classes: a catch clause must only offer confirmed Throwables.
     */
    public function isThrowable(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }

        $throwable = new ClassName(Throwable::class);
        if ($classInfo->name->equals($throwable)) {
            return true;
        }
        return $this->symbolSource->isSubclassOf($className, $throwable);
    }

    /**
     * Check if a class is a PHP attribute.
     * Returns false for unknown classes: an attribute position must only offer
     * confirmed attributes.
     */
    public function isAttribute(ClassName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isAttribute;
    }

    /**
     * Get member access context at position.
     * Used by: Completion (after -> or ::)
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        return $this->memberAccessDetector->detect($document, $ast, $line, $character);
    }

    /**
     * Get variables in scope at position.
     * Used by: Completion (variable names)
     *
     * @return list<ResolvedVariable>
     */
    public function getVariablesInScope(
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);
        $scope = Scope::atOffset($ast, $offset);
        $exprResolver = $this->expressionResolver($document);

        $nearest = [];
        foreach (VariableBindings::before($scope, $offset) as $binding) {
            $nearest[$binding->name] = $binding;
        }

        $variables = [];
        $thisType = $scope->getThisType();
        if ($thisType !== null) {
            $variables[] = new ResolvedVariable('this', $thisType);
            unset($nearest['this']);
        }

        foreach ($nearest as $binding) {
            $variables[] = $exprResolver->resolveBinding($binding, $scope, $ast);
        }

        return $variables;
    }

    /**
     * Get parameters for active call at position.
     * Used by: SignatureHelp, Completion (named args)
     */
    public function getCallContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?CallContext {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);
        $content = $document->getContent();

        $callInfo = $this->callDetector->fromAst($ast, $offset);
        $callable = null;
        $activeParameter = 0;
        $usedNames = [];
        $positionalCount = 0;

        if ($callInfo !== null) {
            [$callNode, $activeParameter, $usedNames, $positionalCount] = $callInfo;
            $callable = $this->resolveCallable($callNode, $ast, $document);
            if ($callable === null) {
                $textCallInfo = $this->callDetector->fromText($ast, $offset, $content, $line);
                if ($textCallInfo !== null) {
                    [$callNode, $activeParameter, $usedNames, $positionalCount] = $textCallInfo;
                    $callable = $this->resolveCallable($callNode, $ast, $document);
                }
            }
        } else {
            $callInfo = $this->callDetector->fromText($ast, $offset, $content, $line);
            if ($callInfo !== null) {
                [$callNode, $activeParameter, $usedNames, $positionalCount] = $callInfo;
                $callable = $this->resolveCallable($callNode, $ast, $document);
            }
        }

        if ($callable === null) {
            return null;
        }

        return new CallContext($callable, $activeParameter, $usedNames, $positionalCount);
    }

    public function getNameContext(TextDocument $document, int $line): NameContext
    {
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        return NameContextFactory::fromAst($ast, $line);
    }

    /**
     * @param FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $call
     * @param array<Stmt> $ast
     */
    private function resolveCallable(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|Attribute $call,
        array $ast,
        TextDocument $document,
    ): ?ResolvedCallable {
        if ($call instanceof FuncCall) {
            return $this->resolveFuncCallCallable($call, $ast, $document);
        }

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            return $this->resolveMethodCallCallable($call, $ast, $document);
        }

        if ($call instanceof StaticCall) {
            $symbol = $this->expressionResolver($document)->resolve($call, $ast);
            return $symbol instanceof ResolvedCallable ? $symbol : null;
        }

        // An attribute usage `#[X(...)]` is a constructor call on the attribute class.
        if ($call instanceof Attribute) {
            return $this->resolveConstructorCallable(new ClassName(ScopeFinder::resolveClassName($call->name)));
        }

        // New_ - resolve constructor
        $className = $this->expressionResolver($document)->resolve($call, $ast)
            ?->getType()
            ?->getResolvableClassNames()[0] ?? null;
        if ($className === null) {
            return null;
        }
        return $this->resolveConstructorCallable($className);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFuncCallCallable(FuncCall $call, array $ast, TextDocument $document): ?ResolvedCallable
    {
        $symbol = $this->expressionResolver($document)->resolve($call, $ast);
        return $symbol instanceof ResolvedCallable ? $symbol : null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveMethodCallCallable(
        MethodCall|NullsafeMethodCall $call,
        array $ast,
        TextDocument $document,
    ): ?ResolvedCallable {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->memberAccessDetector->resolveInstanceAccessClassName($call, $ast, $document);
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

        return $methodInfo;
    }

    /**
     * Resolve a class's constructor to a callable. Shared by `new X(...)` and
     * attribute usages `#[X(...)]`, which are both constructor calls on the class.
     * Uses private visibility so promoted/private constructors are found.
     */
    private function resolveConstructorCallable(ClassName $className): ?ResolvedCallable
    {
        $methodInfo = $this->memberResolver->findMethod(
            $className,
            new MethodName('__construct'),
            Visibility::Private,
        );

        if ($methodInfo === null) {
            return null;
        }

        return $methodInfo;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveNode(Node $node, array $ast, TextDocument $document): ?ResolvedSymbol
    {
        // VarLikeIdentifier extends Identifier, so check it first
        if ($node instanceof VarLikeIdentifier) {
            return $this->resolveVarLikeIdentifier($node, $ast, $document);
        }

        if ($node instanceof Identifier) {
            return $this->resolveIdentifier($node, $ast, $document);
        }

        if ($node instanceof Name) {
            return $this->resolveName($node, $ast, $document);
        }

        if ($node instanceof Variable) {
            return $this->resolveVariable($node, $ast, $document);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveIdentifier(Identifier $node, array $ast, TextDocument $document): ?ResolvedSymbol
    {
        $parent = $node->getAttribute('parent');

        // Instance method call: $obj->method() or $obj?->method()
        if (self::isMethodCall($parent)) {
            /** @var MethodCall|NullsafeMethodCall $parent */
            return $this->resolveMethodCallCallable($parent, $ast, $document);
        }

        // Static method call: ClassName::method()
        if ($parent instanceof StaticCall) {
            return $this->expressionResolver($document)->resolve($parent, $ast);
        }

        // Property fetch: $obj->property or $obj?->property
        if (self::isPropertyFetch($parent)) {
            /** @var PropertyFetch|NullsafePropertyFetch $parent */
            return $this->expressionResolver($document)->resolve($parent, $ast);
        }

        // Class constant or enum case: ClassName::CONSTANT or Enum::Case
        if ($parent instanceof ClassConstFetch) {
            return $this->expressionResolver($document)->resolve($parent, $ast);
        }

        // Named argument: func(name: value) - cursor on 'name'
        if ($parent instanceof Node\Arg && $parent->name === $node) {
            return $this->resolveNamedArgument($node, $parent, $ast, $document);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveName(Name $node, array $ast, TextDocument $document): ?ResolvedSymbol
    {
        $parent = $node->getAttribute('parent');

        if ($parent instanceof FuncCall || $parent instanceof ConstFetch) {
            return $this->expressionResolver($document)->resolve($parent, $ast);
        }

        // Class reference (new, instanceof, static call, type hint, etc.)
        $classNameStr = ScopeFinder::resolveClassName($node);

        $classInfo = $this->symbolSource->lookupClassLike(new ClassName($classNameStr));
        if ($classInfo === null) {
            return null;
        }

        return $classInfo;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveVariable(Variable $node, array $ast, TextDocument $document): ?ResolvedSymbol
    {
        $name = $node->name;
        if (!is_string($name)) {
            return null;
        }

        // Check if this is a parameter declaration
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Param) {
            return $this->resolveParameter($parent);
        }

        return $this->expressionResolver($document)->resolve($node, $ast)
            ?? new ResolvedVariable($name, null);
    }

    private function resolveParameter(Node\Param $param): ParameterInfo
    {
        $enclosingScope = ScopeFinder::findEnclosingScope($param);
        // @codeCoverageIgnoreStart
        if ($enclosingScope === null) {
            throw new LogicException('Param node always has enclosing scope');
        }
        // @codeCoverageIgnoreEnd

        // Find position in parameter list
        $position = 0;
        foreach ($enclosingScope->params as $i => $p) {
            if ($p === $param) {
                $position = $i;
                break;
            }
        }

        $selfContext = null;
        $parentContext = null;

        if ($enclosingScope instanceof Stmt\ClassMethod) {
            $selfContext = ScopeFinder::findEnclosingClassName($enclosingScope);
            // @codeCoverageIgnoreStart
            if ($selfContext === null) {
                throw new LogicException('ClassMethod always has enclosing class');
            }
            // @codeCoverageIgnoreEnd
            $classInfo = $this->symbolSource->lookupClassLike(new ClassName($selfContext));
            $parentContext = $classInfo?->parent?->fqn;
        }

        $paramInfo = \Firehed\PhpLsp\Domain\ParameterInfo::fromNode($param, $position, $selfContext, $parentContext);
        // @codeCoverageIgnoreStart
        if ($paramInfo === null) {
            throw new LogicException('ParameterInfo::fromNode should not return null for valid Param');
        }
        // @codeCoverageIgnoreEnd
        return $paramInfo;
    }

    /**
     * Resolve a named argument to its parameter.
     *
     * @param array<Stmt> $ast
     */
    private function resolveNamedArgument(
        Identifier $node,
        Node\Arg $arg,
        array $ast,
        TextDocument $document,
    ): ?ParameterInfo {
        // Find the call this arg belongs to
        $call = $arg->getAttribute('parent');

        // Handle attribute named arguments
        if ($call instanceof Attribute) {
            return $this->resolveAttributeNamedArgument($node, $call);
        }

        // @codeCoverageIgnoreStart
        if (
            !$call instanceof FuncCall
            && !$call instanceof MethodCall
            && !$call instanceof NullsafeMethodCall
            && !$call instanceof StaticCall
            && !$call instanceof New_
        ) {
            throw new LogicException('Named arg parent must be a call or attribute');
        }
        // @codeCoverageIgnoreEnd

        $callable = $this->resolveCallable($call, $ast, $document);
        if ($callable === null) {
            return null;
        }

        $paramInfo = $callable->getParameterByName($node->toString());
        if ($paramInfo === null) {
            return null;
        }

        return $paramInfo;
    }

    private function resolveAttributeNamedArgument(Identifier $node, Attribute $attribute): ?ParameterInfo
    {
        $classNameStr = ScopeFinder::resolveClassName($attribute->name);

        $callable = $this->resolveConstructorCallable(new ClassName($classNameStr));
        if ($callable === null) {
            return null;
        }

        $paramInfo = $callable->getParameterByName($node->toString());
        if ($paramInfo === null) {
            return null;
        }

        return $paramInfo;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveVarLikeIdentifier(
        VarLikeIdentifier $node,
        array $ast,
        TextDocument $document,
    ): ?ResolvedSymbol {
        $parent = $node->getAttribute('parent');

        // Static property fetch: ClassName::$property
        if ($parent instanceof StaticPropertyFetch) {
            return $this->expressionResolver($document)->resolve($parent, $ast);
        }

        return null;
    }

    private static function isMethodCall(mixed $node): bool
    {
        return $node instanceof MethodCall || $node instanceof NullsafeMethodCall;
    }

    private static function isPropertyFetch(mixed $node): bool
    {
        return $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch;
    }
}
