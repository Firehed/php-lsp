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
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\PropertyName;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use Firehed\PhpLsp\Domain\FunctionInfo;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
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
     * Get members accessible on a type.
     * Used by: Completion (after -> or ::)
     *
     * For instance access (->): returns methods and properties.
     * For static access (::): also includes constants and enum cases.
     *
     * @return list<ResolvedSymbol>
     */
    public function getAccessibleMembers(
        Type $type,
        Visibility $minVisibility,
        bool $staticOnly = false,
    ): array {
        $classNames = $type->getResolvableClassNames();
        if ($classNames === []) {
            return [];
        }

        $members = [];

        foreach ($classNames as $className) {
            $methods = $this->memberResolver->getMethods($className, $minVisibility, $staticOnly);
            foreach ($methods as $methodInfo) {
                $members[] = new ResolvedMethod($methodInfo);
            }

            $properties = $this->memberResolver->getProperties($className, $minVisibility, $staticOnly);
            foreach ($properties as $propertyInfo) {
                $members[] = new ResolvedProperty($propertyInfo);
            }

            if ($staticOnly) {
                $constants = $this->memberResolver->getConstants($className, $minVisibility);
                foreach ($constants as $constantInfo) {
                    $members[] = new ResolvedConstant($constantInfo);
                }

                $enumCases = $this->memberResolver->getEnumCases($className);
                foreach ($enumCases as $enumCaseInfo) {
                    $members[] = new ResolvedEnumCase($enumCaseInfo);
                }
            }
        }

        return $members;
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
            throw new \LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);

        // Find enclosing scope
        $scope = $this->findScopeAtOffset($ast, $offset);
        if ($scope === null) {
            return [];
        }

        $variables = [];
        $seen = [];

        // Add parameters
        foreach ($scope->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $name = $param->var->name;
                if (!isset($seen[$name])) {
                    $type = $this->typeResolver->resolveVariableType($name, $scope, $line, $ast);
                    $variables[] = new ResolvedVariable($name, $type);
                    $seen[$name] = true;
                }
            }
        }

        // Add $this if in a method
        if ($scope instanceof Stmt\ClassMethod) {
            $className = ScopeFinder::findEnclosingClassName($scope);
            if ($className !== null && !isset($seen['this'])) {
                $variables[] = new ResolvedVariable('this', new ClassName($className));
                $seen['this'] = true;
            }
        }

        // Find variable assignments before cursor
        $stmts = $scope->stmts ?? [];
        $this->collectVariablesFromStatements($stmts, $line, $scope, $ast, $variables, $seen);

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
            throw new \LogicException('Parser returned null');
            // @codeCoverageIgnoreEnd
        }

        $offset = $document->offsetAt($line, $character);

        $callInfo = $this->findCallAtPosition($ast, $offset);
        if ($callInfo === null) {
            return null;
        }

        [$callNode, $activeParameter, $usedNames] = $callInfo;

        $callable = $this->resolveCallable($callNode, $ast);
        if ($callable === null) {
            return null;
        }

        return new CallContext($callable, $activeParameter, $usedNames);
    }

    /**
     * @param array<Stmt> $ast
     * @return array{0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_, 1: int, 2: list<string>}|null
     */
    private function findCallAtPosition(array $ast, int $offset): ?array
    {
        $finder = new class ($offset) extends NodeVisitorAbstract {
            /** @var FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|null */
            public ?Node $found = null;
            public int $activeParameter = 0;
            /** @var list<string> */
            public array $usedNames = [];

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    !$node instanceof FuncCall
                    && !$node instanceof MethodCall
                    && !$node instanceof NullsafeMethodCall
                    && !$node instanceof StaticCall
                    && !$node instanceof New_
                ) {
                    return null;
                }

                $startPos = $node->getStartFilePos();
                $endPos = $node->getEndFilePos();

                if ($startPos <= $this->offset && $this->offset <= $endPos) {
                    $activeParam = 0;
                    $usedNames = [];

                    foreach ($node->args as $i => $arg) {
                        if ($arg instanceof \PhpParser\Node\Arg && $arg->name !== null) {
                            $usedNames[] = $arg->name->name;
                        }
                        $argEnd = $arg->getEndFilePos();
                        if ($this->offset > $argEnd) {
                            $activeParam = $i + 1;
                        }
                    }

                    $this->found = $node;
                    $this->activeParameter = $activeParam;
                    $this->usedNames = $usedNames;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            return null;
        }

        return [$finder->found, $finder->activeParameter, $finder->usedNames];
    }

    /**
     * @param FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $call
     * @param array<Stmt> $ast
     */
    private function resolveCallable(
        FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $call,
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

        $functionName = $name->toString();

        // Try user-defined function first
        $funcNode = ScopeFinder::findFunction($functionName, $ast);
        if ($funcNode !== null) {
            $funcInfo = FunctionInfo::fromNode($funcNode);
            return new ResolvedFunction($funcInfo);
        }

        // Fall back to built-in function
        try {
            $funcInfo = FunctionInfo::fromReflection(new \ReflectionFunction($functionName));
            return new ResolvedFunction($funcInfo);
        } catch (\ReflectionException) {
            return null;
        }
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

        $methodInfo = $this->memberResolver->findMethod(
            new ClassName($classNameStr),
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
    private function findScopeAtOffset(array $ast, int $offset): Stmt\Function_|Stmt\ClassMethod|Closure|null
    {
        $visitor = new class ($offset) extends NodeVisitorAbstract {
            public Stmt\Function_|Stmt\ClassMethod|Closure|null $found = null;
            private int $offset;

            public function __construct(int $offset)
            {
                $this->offset = $offset;
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Closure)
                    && $node->getStartFilePos() <= $this->offset
                    && $node->getEndFilePos() >= $this->offset
                ) {
                    $this->found = $node;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->found;
    }

    /**
     * @param array<Stmt|Node> $stmts
     * @param Stmt\Function_|Stmt\ClassMethod|Closure $scope
     * @param array<Stmt> $ast
     * @param list<ResolvedVariable> $variables
     * @param array<string, bool> $seen
     */
    private function collectVariablesFromStatements(
        array $stmts,
        int $line,
        Stmt\Function_|Stmt\ClassMethod|Closure $scope,
        array $ast,
        array &$variables,
        array &$seen,
    ): void {
        foreach ($stmts as $stmt) {
            $stmtLine = $stmt->getStartLine() - 1; // Convert to 0-based
            if ($stmtLine > $line) {
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

            // Recursively check nested structures (if/while/etc.)
            if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                /** @var array<Stmt|Node> $nestedStmts */
                $nestedStmts = $stmt->stmts;
                $this->collectVariablesFromStatements($nestedStmts, $line, $scope, $ast, $variables, $seen);
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
            return $this->resolveName($node);
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
        if (MemberAccessResolver::isMethodCall($parent)) {
            /** @var MethodCall|NullsafeMethodCall $parent */
            return $this->resolveMethodCallCallable($parent, $ast);
        }

        // Static method call: ClassName::method()
        if ($parent instanceof StaticCall) {
            return $this->resolveStaticCallCallable($parent);
        }

        // Property fetch: $obj->property or $obj?->property
        if (MemberAccessResolver::isPropertyFetch($parent)) {
            /** @var PropertyFetch|NullsafePropertyFetch $parent */
            return $this->resolvePropertyFetch($parent, $ast);
        }

        // Class constant or enum case: ClassName::CONSTANT or Enum::Case
        if ($parent instanceof ClassConstFetch) {
            return $this->resolveClassConstFetch($parent);
        }

        return null;
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
    private function resolveVariable(Variable $node, array $ast): ?ResolvedSymbol
    {
        $name = $node->name;
        if (!is_string($name)) {
            return null;
        }

        $type = ExpressionTypeResolver::resolveExpressionType($node, $ast, $this->typeResolver);

        return new ResolvedVariable($name, $type);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolvePropertyFetch(PropertyFetch|NullsafePropertyFetch $fetch, array $ast): ?ResolvedSymbol
    {
        $propertyName = $fetch->name;
        // @codeCoverageIgnoreStart
        if (!$propertyName instanceof Identifier) {
            throw new \LogicException('resolvePropertyFetch called with non-Identifier name');
        }
        // @codeCoverageIgnoreEnd

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

    private function resolveClassConstFetch(ClassConstFetch $fetch): ?ResolvedSymbol
    {
        $constName = $fetch->name;
        // @codeCoverageIgnoreStart
        if (!$constName instanceof Identifier) {
            throw new \LogicException('resolveClassConstFetch called with non-Identifier name');
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            throw new \LogicException('resolveClassConstFetch called with non-Name class');
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
            throw new \LogicException('resolveStaticPropertyFetch called with non-VarLikeIdentifier name');
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            throw new \LogicException('resolveStaticPropertyFetch called with non-Name class');
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
}
