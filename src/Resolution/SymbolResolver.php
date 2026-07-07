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
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\Scope;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\PropertyName;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use Firehed\PhpLsp\Domain\FunctionInfo;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
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
use ReflectionException;
use ReflectionFunction;

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

    public function __construct(
        private readonly ParserService $parser,
        private readonly ClassRepository $classRepository,
        private readonly MemberResolver $memberResolver,
        private readonly TypeResolverInterface $typeResolver,
    ) {
        $this->textFallback = new TextFallbackHelper($memberResolver);
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
     * Check if a class can be instantiated with `new`.
     * Returns true for unknown classes (optimistic filtering).
     */
    public function isInstantiable(ClassName $className): bool
    {
        $classInfo = $this->classRepository->get($className);
        if ($classInfo === null) {
            return true;
        }
        return !$classInfo->isAbstract && $classInfo->kind === ClassKind::Class_;
    }

    /**
     * Check if a class-like can be used as a type hint.
     * Traits are not valid type hints; classes, interfaces, and enums are.
     * Returns true for unknown classes (optimistic filtering).
     */
    public function isValidTypeHint(ClassName $className): bool
    {
        $classInfo = $this->classRepository->get($className);
        if ($classInfo === null) {
            return true;
        }
        return $classInfo->kind !== ClassKind::Trait_;
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

        $offset = $document->offsetAt($line, $character);

        // Use offset - 1 because cursor is after the -> and we want the member access node
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset > 0 ? $offset - 1 : 0);

        if ($node === null) {
            // AST-based detection failed completely, try text-based fallback
            return $this->getMemberAccessContextFromText($document, $ast, $line, $character);
        }

        // Handle identifier/error by checking parent
        if ($node instanceof Identifier || $node instanceof Node\Expr\Error) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node) {
                $node = $parent;
            } else {
                // @codeCoverageIgnoreStart
                // ParserService always sets parent via NodeConnectingVisitor
                throw new LogicException('Node missing parent attribute');
                // @codeCoverageIgnoreEnd
            }
        }

        // Instance access: $obj->member or $obj?->member
        if (self::isMethodCall($node) || self::isPropertyFetch($node)) {
            /** @var MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node */

            // For method calls, check if cursor is past the method name (inside argument list)
            if (self::isMethodCall($node) && $node->name instanceof Identifier) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }

            $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
            $type = $this->resolveInstanceAccessType($node, $ast, $document, $line);
            if ($type !== null) {
                $isThis = $node->var instanceof Variable && $node->var->name === 'this';
                $enclosingClassName = ScopeFinder::findEnclosingClassName($node);
                $classNames = $type->getResolvableClassNames();
                $className = $classNames[0] ?? null;
                $isSameClass = $enclosingClassName !== null && $className !== null
                    && $enclosingClassName === $className->fqn;
                $visibility = ($isThis || $isSameClass) ? Visibility::Private : Visibility::Public;

                return new MemberAccessContext($type, $visibility, MemberAccessKind::Instance, $prefix);
            }
            // AST found the node but couldn't resolve type - try text-based fallback
            return $this->getMemberAccessContextFromText($document, $ast, $line, $character);
        }

        // Static access: ClassName::member
        if ($node instanceof StaticPropertyFetch || $node instanceof StaticCall || $node instanceof ClassConstFetch) {
            // For static calls, check if cursor is past the method name (inside argument list)
            if ($node instanceof StaticCall && $node->name instanceof Identifier) {
                $nameEndPos = $node->name->getEndFilePos();
                if ($offset > $nameEndPos + 1) {
                    return null;
                }
            }
            // AST found a static access node - if it can't be resolved, that's intentional
            // (e.g., self:: outside a class). Don't fall through to text-based.
            return $this->resolveStaticAccessContext($node, $ast, $line);
        }

        // Text-based fallback for incomplete code where AST detection failed
        return $this->getMemberAccessContextFromText($document, $ast, $line, $character);
    }

    /**
     * Text-based fallback for member access detection when AST fails.
     *
     * @param array<Stmt> $ast
     */
    private function getMemberAccessContextFromText(
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        // Try pure text-based resolution first ($this, self::, static::, ClassName::)
        $context = $this->textFallback->getMemberAccessContext($document, $line, $character, $ast);
        if ($context !== null) {
            return $context;
        }

        // For non-$this variables, try AST-based type resolution
        return $this->resolveVariableAccessWithAst($document, $line, $character, $ast);
    }

    /**
     * Resolve non-$this variable access using AST scope.
     *
     * @param array<Stmt> $ast
     */
    private function resolveVariableAccessWithAst(
        TextDocument $document,
        int $line,
        int $character,
        array $ast,
    ): ?MemberAccessContext {
        $lineText = $document->getLine($line);
        $textBeforeCursor = substr($lineText, 0, $character);

        // Match $var-> but not $this->
        if (preg_match('/\$(\w+)\??->([\w]*)$/', $textBeforeCursor, $m) !== 1) {
            return null;
        }
        $varName = $m[1];
        $prefix = $m[2];

        if ($varName === 'this') {
            return null; // Already handled by text fallback
        }

        $offset = $document->offsetAt($line, 0);
        $scope = Scope::atOffset($ast, $offset);

        $type = $this->typeResolver->resolveVariableType($varName, $scope, $line, $ast);

        // Fall back to text-based parameter type resolution when AST resolution fails
        if ($type === null) {
            $type = $this->textFallback->findParameterType($document, $line, $varName, $ast);
        }

        if ($type === null) {
            return null;
        }

        $enclosingClassName = $scope->getSelfContext();
        $classNames = $type->getResolvableClassNames();
        $className = $classNames[0] ?? null;
        $isSameClass = $enclosingClassName !== null && $className !== null
            && $enclosingClassName === $className->fqn;
        $visibility = $isSameClass ? Visibility::Private : Visibility::Public;

        return new MemberAccessContext($type, $visibility, MemberAccessKind::Instance, $prefix);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveStaticAccessContext(
        StaticPropertyFetch|StaticCall|ClassConstFetch $node,
        array $ast,
        int $line,
    ): ?MemberAccessContext {
        $class = $node->class;
        if (!$class instanceof Name) {
            return null;
        }

        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
        $rawName = $class->toString();

        // parent:: has special behavior
        if ($rawName === 'parent') {
            $classNode = ScopeFinder::findClassAtLine($ast, $line);
            if ($classNode === null || $classNode->extends === null) {
                return null;
            }
            $parentClassName = ScopeFinder::resolveExtendsName($classNode);
            assert($parentClassName !== null);
            return new MemberAccessContext(
                new ClassName($parentClassName),
                Visibility::Protected,
                MemberAccessKind::Parent,
                $prefix,
            );
        }

        // For self::, static::, and regular class names
        $className = ScopeFinder::resolveClassNameInContext($class, $node);
        if ($className === null) {
            // @codeCoverageIgnoreStart
            // self::/static:: outside a class - parser error recovery makes this hard to reach
            return null;
            // @codeCoverageIgnoreEnd
        }

        $enclosingClass = ScopeFinder::findClassAtLine($ast, $line);
        $minVisibility = $this->getMinVisibilityForStaticAccess($enclosingClass, $className);

        return new MemberAccessContext(
            new ClassName($className),
            $minVisibility,
            MemberAccessKind::Static,
            $prefix,
        );
    }

    /**
     * Determine minimum visibility for static access from enclosing class.
     *
     * @param class-string $targetClassName
     */
    private function getMinVisibilityForStaticAccess(
        ?Stmt\Class_ $enclosingClass,
        string $targetClassName,
    ): Visibility {
        if ($enclosingClass === null) {
            return Visibility::Public;
        }

        $enclosingClassName = ScopeFinder::getClassLikeName($enclosingClass);
        if ($enclosingClassName === null) {
            return Visibility::Public;
        }

        if ($enclosingClassName === $targetClassName) {
            return Visibility::Private;
        }

        if (ScopeFinder::resolveExtendsName($enclosingClass) === $targetClassName) {
            return Visibility::Protected;
        }

        if (
            $this->classRepository->isSubclassOf(
                new ClassName($enclosingClassName),
                new ClassName($targetClassName),
            )
        ) {
            return Visibility::Protected;
        }

        return Visibility::Public;
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

        $callInfo = $this->findCallAtPosition($ast, $offset);
        $callable = null;
        $activeParameter = 0;
        $usedNames = [];
        $positionalCount = 0;

        if ($callInfo !== null) {
            [$callNode, $activeParameter, $usedNames, $positionalCount] = $callInfo;
            $callable = $this->resolveCallable($callNode, $ast);
            if ($callable === null) {
                // AST found a call node but couldn't resolve type - try text-based
                // This happens with $this-> in incomplete code where AST lacks class context
                $textCallInfo = $this->findCallFromText($ast, $offset, $content, $line);
                if ($textCallInfo !== null) {
                    [$callNode, $activeParameter, $usedNames, $positionalCount] = $textCallInfo;
                    $callable = $this->resolveCallable($callNode, $ast);
                }
            }
        } else {
            // Fallback: text-based detection for incomplete calls where parser
            // couldn't create proper call nodes
            $callInfo = $this->findCallFromText($ast, $offset, $content, $line);
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

    /**
     * @param array<Stmt> $ast
     * @return array{0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_, 1: int, 2: list<string>, 3: int}|null
     */
    private function findCallAtPosition(array $ast, int $offset): ?array
    {
        $finder = new class ($offset) extends NodeVisitorAbstract {
            public FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|null $found = null;
            public int $activeParameter = 0;
            /** @var list<string> */
            public array $usedNames = [];
            public int $positionalCount = 0;

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
                    $positionalCount = 0;
                    $sawNamedArg = false;

                    foreach ($node->args as $i => $arg) {
                        $argEnd = $arg->getEndFilePos();
                        $argBeforeCursor = $this->offset > $argEnd;

                        // Collect ALL named arguments in the call (for completion filtering)
                        if ($arg instanceof Arg && $arg->name !== null) {
                            $usedNames[] = $arg->name->name;
                            $sawNamedArg = true;
                        } elseif (!$sawNamedArg && $argBeforeCursor) {
                            // Only count positional args that are complete and before cursor
                            $positionalCount++;
                        }
                        // Track active parameter index based on cursor position
                        if ($argBeforeCursor) {
                            $activeParam = $i + 1;
                        }
                    }

                    $this->found = $node;
                    $this->activeParameter = $activeParam;
                    $this->usedNames = $usedNames;
                    $this->positionalCount = $positionalCount;
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

        return [$finder->found, $finder->activeParameter, $finder->usedNames, $finder->positionalCount];
    }

    /**
     * Text-based fallback for detecting call context when AST-based detection fails.
     * Handles incomplete code where the parser couldn't create proper call nodes.
     *
     * @param array<Stmt> $ast
     * @return array{0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_, 1: int, 2: list<string>, 3: int}|null
     */
    private function findCallFromText(array $ast, int $offset, string $content, int $line): ?array
    {
        // Find the last unclosed `(` before cursor
        $parenPos = $this->findUnclosedParen($content, $offset);
        if ($parenPos === null) {
            return null;
        }

        // Get text before the opening paren
        $textBeforeParen = substr($content, 0, $parenPos);

        // Try to match call patterns and resolve the callable
        $callNode = $this->parseCallPattern($textBeforeParen, $ast, $line, $content);
        if ($callNode === null) {
            return null;
        }

        // Parse arguments between paren and cursor to determine position/used names
        $argsText = substr($content, $parenPos + 1, $offset - $parenPos - 1);
        [$activeParam, $usedNames, $positionalCount] = $this->parseArgsFromText($argsText);

        return [$callNode, $activeParam, $usedNames, $positionalCount];
    }

    /**
     * Find the position of the last unclosed `(` before the given offset.
     */
    private function findUnclosedParen(string $content, int $offset): ?int
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $content[$i];
            if ($char === ')') {
                $depth++;
            } elseif ($char === '(') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            } elseif ($char === ';' || $char === '{' || $char === '}') {
                // Statement boundary - stop searching
                return null;
            }
        }
        return null;
    }

    /**
     * Parse call pattern from text before opening paren.
     *
     * @param array<Stmt> $ast
     * @return FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|null
     */
    private function parseCallPattern(
        string $textBeforeParen,
        array $ast,
        int $line,
        string $content,
    ): FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|null {
        $text = rtrim($textBeforeParen);

        // Static call: ClassName::methodName
        if (preg_match('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::(\w+)\s*$/', $text, $m) === 1) {
            $className = $m[1];
            $methodName = $m[2];
            $name = $this->resolveNameFromText($className, $ast, $line);
            return new StaticCall($name, new Identifier($methodName));
        }

        // Instance call: $var->methodName or $var?->methodName
        if (preg_match('/\$(\w+)(\?)?->(\w+)\s*$/', $text, $m) === 1) {
            $varName = $m[1];
            $isNullsafe = $m[2] === '?';
            $methodName = $m[3];
            $var = new Variable($varName);
            // For $this, store the enclosing class so resolveMethodCallCallable can use it
            if ($varName === 'this') {
                $enclosingClass = $this->findEnclosingClassForLine($ast, $line)
                    ?? $this->textFallback->findEnclosingClassFromContent($content, $line);
                if ($enclosingClass !== null) {
                    $var->setAttribute('resolvedType', new ClassName($enclosingClass));
                }
            }
            return $isNullsafe
                ? new NullsafeMethodCall($var, new Identifier($methodName))
                : new MethodCall($var, new Identifier($methodName));
        }

        // Constructor: new ClassName
        if (preg_match('/\bnew\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*$/', $text, $m) === 1) {
            $className = $m[1];
            $name = $this->resolveNameFromText($className, $ast, $line);
            return new New_($name);
        }

        // Function call: functionName
        if (preg_match('/\b(\w+)\s*$/', $text, $m) === 1) {
            $funcName = $m[1];
            $keywords = ['if', 'while', 'for', 'foreach', 'switch', 'catch', 'array', 'list'];
            if (!in_array(strtolower($funcName), $keywords, true)) {
                return new FuncCall(new Name($funcName));
            }
        }

        return null;
    }

    /**
     * Resolve a class name from text, looking up use statements and namespace.
     *
     * @param array<Stmt> $ast
     */
    private function resolveNameFromText(string $className, array $ast, int $line): Name
    {
        $name = new Name($className);

        // Already fully qualified
        if (str_starts_with($className, '\\')) {
            $name->setAttribute('resolvedName', new Name\FullyQualified(ltrim($className, '\\')));
            return $name;
        }

        // Check use statements first
        $resolvedFromUse = ScopeFinder::resolveFromUseStatements($className, $ast);
        if ($resolvedFromUse !== null) {
            $name->setAttribute('resolvedName', new Name\FullyQualified($resolvedFromUse));
            return $name;
        }

        // Fall back to namespace prefix
        $namespace = ScopeFinder::findNamespaceAtLine($ast, $line);
        if ($namespace !== null) {
            $name->setAttribute('resolvedName', new Name\FullyQualified($namespace . '\\' . $className));
        }

        return $name;
    }

    /**
     * Parse argument text to determine active parameter and used named arguments.
     *
     * @return array{0: int, 1: list<string>, 2: int}
     */
    private function parseArgsFromText(string $argsText): array
    {
        $activeParam = 0;
        $usedNames = [];
        $positionalCount = 0;
        $sawNamedArg = false;

        // Simple parsing: count commas and look for `name:` patterns
        // This is approximate but handles common cases
        $depth = 0;
        $currentArg = '';

        for ($i = 0; $i < strlen($argsText); $i++) {
            $char = $argsText[$i];

            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $currentArg .= $char;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                $currentArg .= $char;
            } elseif ($char === ',' && $depth === 0) {
                // End of argument
                $this->processArgText($currentArg, $usedNames, $positionalCount, $sawNamedArg);
                $activeParam++;
                $currentArg = '';
            } else {
                $currentArg .= $char;
            }
        }

        // Process final partial argument (cursor is here)
        // Extract named arg if present, but don't increment positional count
        // since the argument value isn't complete
        if (preg_match('/^(\w+)\s*:/', trim($currentArg), $m) === 1) {
            $usedNames[] = $m[1];
        }

        return [$activeParam, $usedNames, $positionalCount];
    }

    /**
     * Process a single argument's text to extract named arg info.
     *
     * @param list<string> $usedNames
     */
    private function processArgText(string $argText, array &$usedNames, int &$positionalCount, bool &$sawNamedArg): void
    {
        $argText = trim($argText);
        if ($argText === '') {
            return;
        }

        // Check for named argument pattern: `name:`
        if (preg_match('/^(\w+)\s*:/', $argText, $m) === 1) {
            $usedNames[] = $m[1];
            $sawNamedArg = true;
        } elseif (!$sawNamedArg) {
            $positionalCount++;
        }
    }

    /**
     * Find the enclosing class name for a given line number.
     *
     * @param array<Stmt> $ast
     * @return class-string|null
     */
    private function findEnclosingClassForLine(array $ast, int $line): ?string
    {
        $finder = new NodeFinder();
        $classLikes = $finder->find($ast, function (Node $node) use ($line) {
            if (
                !$node instanceof Stmt\Class_
                && !$node instanceof Stmt\Trait_
                && !$node instanceof Stmt\Enum_
            ) {
                return false;
            }
            $startLine = $node->getStartLine();
            $endLine = $node->getEndLine();
            return $startLine <= $line + 1 && $line + 1 <= $endLine;
        });

        if (count($classLikes) === 0) {
            return null;
        }

        $classNode = $classLikes[0];
        assert(
            $classNode instanceof Stmt\Class_
            || $classNode instanceof Stmt\Trait_
            || $classNode instanceof Stmt\Enum_
        );
        return ScopeFinder::getClassLikeName($classNode);
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

        return $this->resolveFunctionByName($name->toString(), $ast);
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

        $className = $this->resolveInstanceAccessClassName($call, $ast);
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

    /**
     * Resolve the type of the object in an instance member access.
     *
     * @param array<Stmt> $ast
     */
    private function resolveInstanceAccessType(
        MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node,
        array $ast,
        ?TextDocument $document = null,
        ?int $line = null,
    ): ?Type {
        // Check for pre-resolved type from text-based fallback
        $resolvedType = $node->var->getAttribute('resolvedType');
        if ($resolvedType instanceof Type) {
            return $resolvedType;
        }

        $type = ExpressionTypeResolver::resolveExpressionType($node->var, $ast, $this->typeResolver);
        if ($type !== null) {
            return $type;
        }

        // If AST-based resolution failed and we have document context, try text-based
        // class detection for expressions that start with $this
        if ($document !== null && $line !== null && $this->expressionStartsWithThis($node->var)) {
            $enclosingClass = $this->findEnclosingClassForLine($ast, $line)
                ?? $this->textFallback->findEnclosingClass($document, $line);
            if ($enclosingClass !== null) {
                // Set the resolved type on the $this variable so type resolution can proceed
                $thisVar = $this->findThisVariable($node->var);
                if ($thisVar !== null) {
                    $thisVar->setAttribute('resolvedType', new ClassName($enclosingClass));
                    // Retry expression type resolution with the $this type set
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
     * Check if an expression starts with $this.
     */
    private function expressionStartsWithThis(Node\Expr $expr): bool
    {
        if ($expr instanceof Variable && $expr->name === 'this') {
            return true;
        }
        if (
            $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch
            || $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall
        ) {
            return $this->expressionStartsWithThis($expr->var);
        }
        return false;
    }

    /**
     * Find the $this variable in an expression chain.
     */
    private function findThisVariable(Node\Expr $expr): ?Variable
    {
        if ($expr instanceof Variable && $expr->name === 'this') {
            return $expr;
        }
        if (
            $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch
            || $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall
        ) {
            return $this->findThisVariable($expr->var);
        }
        // @codeCoverageIgnoreStart
        throw new \LogicException('findThisVariable called with unhandled expression type');
        // @codeCoverageIgnoreEnd
    }

    /**
     * Resolve the class name of the object in an instance member access.
     *
     * @param array<Stmt> $ast
     */
    private function resolveInstanceAccessClassName(
        MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node,
        array $ast,
    ): ?ClassName {
        $type = $this->resolveInstanceAccessType($node, $ast);
        $classNames = $type?->getResolvableClassNames() ?? [];
        return $classNames[0] ?? null;
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

        // Class reference (new, instanceof, static call, type hint, etc.)
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
    private function resolveFunctionCall(Name $node, array $ast): ?ResolvedFunction
    {
        return $this->resolveFunctionByName($node->toString(), $ast);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveFunctionByName(string $functionName, array $ast): ?ResolvedFunction
    {
        // Try user-defined function first
        $funcNode = ScopeFinder::findFunction($functionName, $ast);
        if ($funcNode !== null) {
            return new ResolvedFunction(FunctionInfo::fromNode($funcNode));
        }

        // Fall back to built-in function via reflection
        try {
            $funcInfo = FunctionInfo::fromReflection(new ReflectionFunction($functionName));
            return new ResolvedFunction($funcInfo);
        } catch (ReflectionException) {
            return null;
        }
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
            $classInfo = $this->classRepository->get(new ClassName($selfContext));
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
        $className = new ClassName($classNameStr);

        // Resolve constructor of attribute class
        $methodInfo = $this->memberResolver->findMethod(
            $className,
            new MethodName('__construct'),
            Visibility::Private,
        );

        if ($methodInfo === null) {
            return null;
        }

        $callable = new ResolvedMethod($methodInfo);
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

        $className = $this->resolveInstanceAccessClassName($fetch, $ast);
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
