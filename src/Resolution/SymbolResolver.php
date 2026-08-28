<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Utility\NodeAtPosition;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\PropertyName;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use Firehed\PhpLsp\Domain\FunctionName;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\VarLikeIdentifier;
use PhpParser\Node\Identifier;
use PhpParser\Node\Attribute;
use PhpParser\Node\Stmt;
use LogicException;
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
 * - findImplementations(ClassName $interface): array<ResolvedClass>
 * - findSubtypes(ClassName $class): array<ResolvedClass>
 * - findSupertypes(ClassName $class): array<ResolvedClass>
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
        private readonly TypeResolverInterface $typeResolver,
    ) {
        $this->textFallback = new TextFallbackHelper($memberResolver);
        $this->callDetector = new CallContextDetector($this->textFallback);
        $this->memberAccessDetector = new MemberAccessDetector(
            $symbolSource,
            $typeResolver,
            $this->textFallback,
        );
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

        return $this->resolveNode($node, $ast);
    }

    /**
     * Get members accessible on a type.
     * Used by: Completion (after -> or ::)
     *
     * For instance access: returns methods and properties.
     * For static access: also includes constants and enum cases.
     *
     * Falls back to text-based extraction when AST-based resolution fails.
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
            $classMembers = $this->getMembersForClass($className, $minVisibility, $filter, $includeStatic);

            // Fall back to text-based extraction when AST-based resolution fails
            if ($classMembers === []) {
                $classMembers = $this->textFallback->extractMembers($document, $className, $minVisibility, $filter);
            }

            $members = array_merge($members, $classMembers);
        }

        return $members;
    }

    /**
     * Get members for a single class using AST/reflection.
     *
     * @return list<ResolvedMember>
     */
    private function getMembersForClass(
        ClassName $className,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $includeStatic,
    ): array {
        $members = [];

        $methods = $this->memberResolver->getMethods($className, $minVisibility, $filter);
        foreach ($methods as $methodInfo) {
            $members[] = new ResolvedMethod($methodInfo);
        }

        $properties = $this->memberResolver->getProperties($className, $minVisibility, $filter);
        foreach ($properties as $propertyInfo) {
            $members[] = new ResolvedProperty($propertyInfo);
        }

        if ($includeStatic) {
            $constants = $this->memberResolver->getConstants($className, $minVisibility);
            foreach ($constants as $constantInfo) {
                $members[] = new ResolvedConstant($constantInfo);
            }

            $enumCases = $this->memberResolver->getEnumCases($className);
            foreach ($enumCases as $enumCaseInfo) {
                $members[] = new ResolvedEnumCase($enumCaseInfo);
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

        $variables = [];
        $seen = [];

        // Add parameters
        foreach ($scope->getParams() as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $name = $param->var->name;
                if (!isset($seen[$name])) {
                    $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                    $variables[] = new ResolvedVariable($name, $type);
                    $seen[$name] = true;
                }
            }
        }

        // Add $this when bound (non-static methods)
        $thisType = $scope->getThisType();
        if ($thisType !== null && !isset($seen['this'])) {
            $variables[] = new ResolvedVariable('this', $thisType);
            $seen['this'] = true;
        }

        // Find variable assignments before cursor
        $this->collectVariablesFromStatements($scope->getStatements(), $line, $scope, $ast, $variables, $seen);

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
            $callable = $this->resolveCallable($callNode, $ast);
            if ($callable === null) {
                $textCallInfo = $this->callDetector->fromText($ast, $offset, $content, $line);
                if ($textCallInfo !== null) {
                    [$callNode, $activeParameter, $usedNames, $positionalCount] = $textCallInfo;
                    $callable = $this->resolveCallable($callNode, $ast);
                }
            }
        } else {
            $callInfo = $this->callDetector->fromText($ast, $offset, $content, $line);
            if ($callInfo !== null) {
                [$callNode, $activeParameter, $usedNames, $positionalCount] = $callInfo;
                $callable = $this->resolveCallable($callNode, $ast);
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
    ): ?ResolvedCallable {
        if ($call instanceof FuncCall) {
            return $this->resolveFuncCallCallable($call, $ast);
        }

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            return $this->resolveMethodCallCallable($call, $ast);
        }

        if ($call instanceof StaticCall) {
            return $this->resolveStaticCallCallable($call);
        }

        // An attribute usage `#[X(...)]` is a constructor call on the attribute class.
        if ($call instanceof Attribute) {
            return $this->resolveConstructorCallable(new ClassName(ScopeFinder::resolveClassName($call->name)));
        }

        // New_ - resolve constructor
        return $this->resolveNewCallable($call);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFuncCallCallable(FuncCall $call, array $ast): ?ResolvedCallable
    {
        $name = $call->name;
        if (!$name instanceof Name) {
            return null;
        }

        return $this->resolveFunctionByName($name, $ast);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveMethodCallCallable(MethodCall|NullsafeMethodCall $call, array $ast): ?ResolvedCallable
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->memberAccessDetector->resolveInstanceAccessClassName($call, $ast);
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

    private function resolveStaticCallCallable(StaticCall $call): ?ResolvedCallable
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

    private function resolveNewCallable(New_ $call): ?ResolvedCallable
    {
        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $classNameStr = ScopeFinder::resolveClassNameInContext($class, $call);
        if ($classNameStr === null) {
            return null;
        }

        return $this->resolveConstructorCallable(new ClassName($classNameStr));
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

        return new ResolvedMethod($methodInfo);
    }

    /**
     * @param array<Stmt> $ast
     * @return Stmt\Function_|Stmt\ClassMethod|Closure|null
     */
    /**
     * @param array<Stmt|Node> $stmts
     * @param array<Stmt> $ast
     * @param list<ResolvedVariable> $variables
     * @param array<string, bool> $seen
     */
    private function collectVariablesFromStatements(
        array $stmts,
        int $line,
        Scope $scope,
        array $ast,
        array &$variables,
        array &$seen,
    ): void {
        foreach ($stmts as $stmt) {
            $stmtLine = $stmt->getStartLine() - 1; // Convert to 0-based
            if ($stmtLine > $line) {
                continue;
            }

            // Nested function/class declarations introduce their own scope;
            // their bodies must not contribute variables to this one.
            if ($stmt instanceof Stmt\Function_ || $stmt instanceof Stmt\ClassLike) {
                continue;
            }

            if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Assign) {
                $assign = $stmt->expr;
                if ($assign->var instanceof Variable && is_string($assign->var->name)) {
                    $name = $assign->var->name;
                    if (!isset($seen[$name])) {
                        $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                        $variables[] = new ResolvedVariable($name, $type);
                        $seen[$name] = true;
                    }
                }
            }

            // Collect foreach variables
            if ($stmt instanceof Stmt\Foreach_) {
                if ($stmt->valueVar instanceof Variable && is_string($stmt->valueVar->name)) {
                    $name = $stmt->valueVar->name;
                    if (!isset($seen[$name])) {
                        $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                        $variables[] = new ResolvedVariable($name, $type);
                        $seen[$name] = true;
                    }
                }
                if ($stmt->keyVar instanceof Variable && is_string($stmt->keyVar->name)) {
                    $name = $stmt->keyVar->name;
                    if (!isset($seen[$name])) {
                        $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                        $variables[] = new ResolvedVariable($name, $type);
                        $seen[$name] = true;
                    }
                }
            }

            // Recursively check nested structures (if/while/etc.)
            if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                /** @var array<Stmt|Node> $nestedStmts */
                $nestedStmts = $stmt->stmts;
                $this->collectVariablesFromStatements($nestedStmts, $line, $scope, $ast, $variables, $seen);
            }

            // Handle try/catch - process catch blocks
            if ($stmt instanceof Stmt\TryCatch) {
                foreach ($stmt->catches as $catch) {
                    if ($catch->var !== null && is_string($catch->var->name)) {
                        $name = $catch->var->name;
                        if (!isset($seen[$name])) {
                            $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                            $variables[] = new ResolvedVariable($name, $type);
                            $seen[$name] = true;
                        }
                    }
                    $this->collectVariablesFromStatements($catch->stmts, $line, $scope, $ast, $variables, $seen);
                }
            }
        }
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
            return $this->resolveName($node, $ast);
        }

        if ($node instanceof Variable) {
            return $this->resolveVariable($node, $ast);
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
        if (self::isMethodCall($parent)) {
            /** @var MethodCall|NullsafeMethodCall $parent */
            return $this->resolveMethodCallCallable($parent, $ast);
        }

        // Static method call: ClassName::method()
        if ($parent instanceof StaticCall) {
            return $this->resolveStaticCallCallable($parent);
        }

        // Property fetch: $obj->property or $obj?->property
        if (self::isPropertyFetch($parent)) {
            /** @var PropertyFetch|NullsafePropertyFetch $parent */
            return $this->resolvePropertyFetch($parent, $ast);
        }

        // Class constant or enum case: ClassName::CONSTANT or Enum::Case
        if ($parent instanceof ClassConstFetch) {
            return $this->resolveClassConstFetch($parent);
        }

        // Named argument: func(name: value) - cursor on 'name'
        if ($parent instanceof Node\Arg && $parent->name === $node) {
            return $this->resolveNamedArgument($node, $parent, $ast);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveName(Name $node, array $ast): ?ResolvedSymbol
    {
        $parent = $node->getAttribute('parent');

        // Function call: resolve to ResolvedFunction
        if ($parent instanceof FuncCall) {
            return $this->resolveFunctionCall($node, $ast);
        }

        // Global constant: resolve to ResolvedGlobalConstant
        if ($parent instanceof ConstFetch) {
            return $this->resolveConstFetch($node);
        }

        // Class reference (new, instanceof, static call, type hint, etc.)
        $classNameStr = ScopeFinder::resolveClassName($node);

        $classInfo = $this->symbolSource->lookupClassLike(new ClassName($classNameStr));
        if ($classInfo === null) {
            return null;
        }

        return new ResolvedClass($classInfo);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFunctionCall(Name $node, array $ast): ?ResolvedFunction
    {
        return $this->resolveFunctionByName($node, $ast);
    }

    private function resolveConstFetch(Name $node): ?ResolvedGlobalConstant
    {
        $name = ScopeFinder::resolveName($node);
        $constantInfo = $this->symbolSource->lookupConstant(GlobalConstantName::fromFullyQualified($name));
        if ($constantInfo === null) {
            return null;
        }

        return new ResolvedGlobalConstant($constantInfo);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFunctionByName(Name $name, array $ast): ?ResolvedFunction
    {
        $shortName = $name->toString();
        $line = $name->getStartLine() - 1;
        $context = NameContextFactory::fromAst($ast, $line);

        foreach ($context->candidates($shortName, NameKind::Function_) as $candidate) {
            $funcInfo = $this->symbolSource->lookupFunction(FunctionName::fromFullyQualified($candidate));
            if ($funcInfo !== null) {
                return new ResolvedFunction($funcInfo);
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveVariable(Variable $node, array $ast): ?ResolvedSymbol
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

        $type = ExpressionTypeResolver::resolveExpressionType($node, $ast, $this->typeResolver);

        return new ResolvedVariable($name, $type);
    }

    private function resolveParameter(Node\Param $param): ResolvedParameter
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
        return new ResolvedParameter($paramInfo);
    }

    /**
     * Resolve a named argument to its parameter.
     *
     * @param array<Stmt> $ast
     */
    private function resolveNamedArgument(Identifier $node, Node\Arg $arg, array $ast): ?ResolvedParameter
    {
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

        $callable = $this->resolveCallable($call, $ast);
        if ($callable === null) {
            return null;
        }

        $paramInfo = $callable->getParameterByName($node->toString());
        if ($paramInfo === null) {
            return null;
        }

        return new ResolvedParameter($paramInfo);
    }

    private function resolveAttributeNamedArgument(Identifier $node, Attribute $attribute): ?ResolvedParameter
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

        return new ResolvedParameter($paramInfo);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolvePropertyFetch(PropertyFetch|NullsafePropertyFetch $fetch, array $ast): ?ResolvedSymbol
    {
        $propertyName = $fetch->name;
        // @codeCoverageIgnoreStart
        if (!$propertyName instanceof Identifier) {
            throw new LogicException('resolvePropertyFetch called with non-Identifier name');
        }
        // @codeCoverageIgnoreEnd

        $className = $this->memberAccessDetector->resolveInstanceAccessClassName($fetch, $ast);
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

    private function resolveClassConstFetch(ClassConstFetch $fetch): ?ResolvedSymbol
    {
        $constName = $fetch->name;
        // @codeCoverageIgnoreStart
        if (!$constName instanceof Identifier) {
            throw new LogicException('resolveClassConstFetch called with non-Identifier name');
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            throw new LogicException('resolveClassConstFetch called with non-Name class');
        }
        // @codeCoverageIgnoreEnd

        $classNameStr = ScopeFinder::resolveClassNameInContext($class, $fetch);
        if ($classNameStr === null) {
            return null;
        }

        $className = new ClassName($classNameStr);

        // Check if it's an enum case first
        $enumCaseInfo = $this->memberResolver->findEnumCase(
            $className,
            new EnumCaseName($constName->toString()),
        );

        if ($enumCaseInfo !== null) {
            return new ResolvedEnumCase($enumCaseInfo);
        }

        // Otherwise it's a class constant
        $constantInfo = $this->memberResolver->findConstant(
            $className,
            new ConstantName($constName->toString()),
            Visibility::Private,
        );

        if ($constantInfo === null) {
            return null;
        }

        return new ResolvedConstant($constantInfo);
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
        // @codeCoverageIgnoreStart
        if (!$propertyName instanceof VarLikeIdentifier) {
            throw new LogicException('resolveStaticPropertyFetch called with non-VarLikeIdentifier name');
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            throw new LogicException('resolveStaticPropertyFetch called with non-Name class');
        }
        // @codeCoverageIgnoreEnd

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

    private static function isMethodCall(mixed $node): bool
    {
        return $node instanceof MethodCall || $node instanceof NullsafeMethodCall;
    }

    private static function isPropertyFetch(mixed $node): bool
    {
        return $node instanceof PropertyFetch || $node instanceof NullsafePropertyFetch;
    }
}
