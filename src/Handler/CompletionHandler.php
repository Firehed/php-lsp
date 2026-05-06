<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\TypeFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
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
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * @phpstan-type CompletionItem array{
 *   label: string,
 *   kind?: int,
 *   detail?: string,
 *   documentation?: string,
 * }
 */
final class CompletionHandler implements HandlerInterface
{
    // LSP CompletionItemKind constants
    private const KIND_METHOD = 2;
    private const KIND_FUNCTION = 3;
    private const KIND_VARIABLE = 6;
    private const KIND_CLASS = 7;
    private const KIND_PROPERTY = 10;
    private const KIND_KEYWORD = 14;
    private const KIND_ENUM_MEMBER = 20;
    private const KIND_CONSTANT = 21;

    // Matches property type continuations: "private ?", "public int|", "protected Foo&"
    private const PROPERTY_TYPE_PATTERN = '/(?:public|private|protected)\s+(?:readonly\s+)?(?:\w+\s*)?[?|&]\s*(\w*)$/';

    private static function matchesPrefix(string $name, string $prefix): bool
    {
        return $prefix === '' || str_starts_with(strtolower($name), strtolower($prefix));
    }

    /**
     * @param CompletionItem $item
     * @return CompletionItem
     */
    private static function withDocumentation(array $item, string|false|null $docText): array
    {
        if ($docText !== null && $docText !== false && $docText !== '') {
            $doc = DocblockParser::extractDescription($docText);
            if ($doc !== '') {
                $item['documentation'] = $doc;
            }
        }
        return $item;
    }

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly SymbolIndex $symbolIndex,
        private readonly ClassRepository $classRepository,
        private readonly TypeResolverInterface $typeResolver,
        private readonly MemberAccessResolver $memberAccessResolver,
        private readonly SymbolResolver $symbolResolver,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/completion';
    }

    /**
     * @return array{
     *   isIncomplete: bool,
     *   items: list<CompletionItem>,
     * }|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        // Skip completions inside comments, strings, heredocs
        $offset = $document->offsetAt($line, $character);
        if (!ContextDetector::isCompletable($document->getContent(), $offset)) {
            return [
                'isIncomplete' => false,
                'items' => [],
            ];
        }

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            throw new \LogicException('Parser returned null with error-collecting handler');
            // @codeCoverageIgnoreEnd
        }

        // Get text before cursor to determine completion context
        $lineText = $document->getLine($line);
        $textBeforeCursor = substr($lineText, 0, $character);

        $items = $this->getCompletionItems($textBeforeCursor, $ast, $line, $offset);

        return [
            'isIncomplete' => false,
            'items' => $items,
        ];
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getCompletionItems(string $textBeforeCursor, array $ast, int $line, int $offset): array
    {
        // AST-based member/static access detection
        // Use offset - 1 because cursor is after the -> and we want the member access node
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset > 0 ? $offset - 1 : 0);
        if ($node !== null) {
            $result = $this->handleMemberAccessNode($node, $ast, $line);
            if ($result !== null) {
                return $result;
            }
        }

        // Variable completion ($var)
        if (preg_match('/\$(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getVariableCompletions($prefix, $ast, $line);
        }

        // new ClassName completion - suggest imported classes and indexed instantiable types
        if (preg_match('/new\s+(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->getImportedClassCompletions($prefix, $ast);
            $indexedItems = $this->getIndexedClassCompletions($prefix, [SymbolKind::Class_, SymbolKind::Enum_]);
            $items = array_merge($items, $indexedItems);
            return $this->deduplicateCompletions($items);
        }

        // After visibility keyword - suggest function, static, readonly, const, or types
        // Must check before general type hint context since both patterns overlap
        if (preg_match('/(?:public|private|protected)\s+(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->filterKeywords(self::KEYWORDS_AFTER_VISIBILITY, $prefix);
            $items = array_merge($items, $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property));
            return $this->deduplicateCompletions($items);
        }

        // Return type context - after ): with optional space
        if (preg_match('/\):\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::ReturnType);
        }

        // Return type context - nullable/union/intersection (e.g., "): ?", "): int|", "): Foo&")
        if (preg_match('/\):\s*(?:\?\s*|(?:\w+\s*[|&]\s*)+)(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::ReturnType);
        }

        // Property type context - nullable/union/intersection after visibility keyword
        if (preg_match(self::PROPERTY_TYPE_PATTERN, $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property);
        }

        // Parameter type context - fallback for type positions not matched above
        // Matches after (, ,, ?, |, & which occur in parameter lists and complex types
        if (preg_match('/[(,?|&]\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            return $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Parameter);
        }

        // Class body context - only class-level keywords, no functions
        if ($this->isInClassBody($textBeforeCursor)) {
            if (preg_match('/(?:^|[\s{;])(\w+)$/', $textBeforeCursor, $matches) === 1) {
                $prefix = $matches[1];
                return $this->filterKeywords(self::KEYWORDS_CLASS_BODY, $prefix);
            }
            return [];
        }

        // Function/class/keyword completion (at start of expression or after operators)
        if (preg_match('/(?:^|[(\s=,!&|])(\w+)$/', $textBeforeCursor, $matches) === 1) {
            $prefix = $matches[1];
            $items = $this->filterKeywords(self::KEYWORDS_ALL, $prefix);
            $items = array_merge($items, $this->getFunctionCompletions($prefix, $ast));
            $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));
            $items = array_merge($items, $this->getIndexedClassCompletions($prefix, [
                SymbolKind::Class_,
                SymbolKind::Interface_,
                SymbolKind::Trait_,
                SymbolKind::Enum_,
            ]));
            return $this->deduplicateCompletions($items);
        }

        return [];
    }

    /**
     * Handle member/static access detected via AST analysis.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>|null
     */
    private function handleMemberAccessNode(Node $node, array $ast, int $line): ?array
    {
        // Handle identifier/error by checking parent
        if ($node instanceof Identifier || $node instanceof Node\Expr\Error) {
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node) {
                $node = $parent;
            } else {
                // @codeCoverageIgnoreStart
                // ParserService always sets parent via NodeConnectingVisitor
                return null;
                // @codeCoverageIgnoreEnd
            }
        }

        // Member access: $obj->member or $obj?->member
        if (MemberAccessResolver::isMethodCall($node) || MemberAccessResolver::isPropertyFetch($node)) {
            /** @var MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node */
            return $this->handleMemberAccess($node, $ast);
        }

        // Static access: ClassName::member
        if ($node instanceof StaticPropertyFetch || $node instanceof StaticCall || $node instanceof ClassConstFetch) {
            return $this->handleStaticAccess($node, $ast, $line);
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function handleMemberAccess(
        MethodCall|NullsafeMethodCall|PropertyFetch|NullsafePropertyFetch $node,
        array $ast,
    ): array {
        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';

        $className = $this->memberAccessResolver->resolveObjectClassName($node->var, $ast);
        if ($className === null) {
            return [];
        }

        $isThis = $node->var instanceof Variable && $node->var->name === 'this';
        $enclosingClassName = ScopeFinder::findEnclosingClassName($node);
        $isSameClass = $enclosingClassName !== null && $enclosingClassName === $className->fqn;
        $visibility = ($isThis || $isSameClass) ? Visibility::Private : Visibility::Public;

        $members = $this->symbolResolver->getAccessibleMembers($className, $visibility, staticOnly: false);

        $items = [];
        foreach ($members as $member) {
            if (!$member instanceof ResolvedMember) {
                continue;
            }
            if (self::matchesPrefix($member->getName()->name, $prefix)) {
                $items[] = $this->formatResolvedMemberCompletion($member);
            }
        }

        return $items;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function handleStaticAccess(
        StaticPropertyFetch|StaticCall|ClassConstFetch $node,
        array $ast,
        int $line,
    ): array {
        $class = $node->class;
        if (!$class instanceof Name) {
            return [];
        }

        $prefix = $node->name instanceof Identifier ? $node->name->toString() : '';
        $rawName = $class->toString();

        // parent:: has special completion behavior - only shows parent's methods
        if ($rawName === 'parent') {
            return $this->getParentCompletions($prefix, $ast, $line);
        }

        // For self::, static::, and regular class names, resolve and get completions
        $className = ScopeFinder::resolveClassNameInContext($class, $node);
        if ($className === null) {
            return [];
        }

        return $this->getStaticCompletions($className, $prefix, $ast, $line);
    }

    /**
     * Get completions for parent:: - methods from the parent class.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getParentCompletions(string $prefix, array $ast, int $line): array
    {
        $classNode = ScopeFinder::findClassAtLine($ast, $line);
        if ($classNode === null || $classNode->extends === null) {
            return [];
        }

        $parentClassName = ScopeFinder::resolveExtendsName($classNode);
        assert($parentClassName !== null);

        // Get all members (both static and instance) but only include methods
        $members = $this->symbolResolver->getAccessibleMembers(
            new ClassName($parentClassName),
            Visibility::Protected,
            staticOnly: false,
        );

        $items = [];
        foreach ($members as $member) {
            if (!$member instanceof ResolvedMethod) {
                continue;
            }
            if (self::matchesPrefix($member->getName()->name, $prefix)) {
                $items[] = $this->formatResolvedMemberCompletion($member);
            }
        }

        return $items;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getStaticCompletions(string $className, string $prefix, array $ast, int $line): array
    {
        // Resolve short name to FQCN using imports
        $resolvedClassName = $this->resolveClassName($className, $ast);

        $enclosingClass = ScopeFinder::findClassAtLine($ast, $line);
        $minVisibility = $this->getMinVisibilityForAccess($enclosingClass, $resolvedClassName);

        $members = $this->symbolResolver->getAccessibleMembers(
            new ClassName($resolvedClassName),
            $minVisibility,
            staticOnly: true,
        );

        $items = [];
        foreach ($members as $member) {
            if (!$member instanceof ResolvedMember) {
                continue;
            }
            if (self::matchesPrefix($member->getName()->name, $prefix)) {
                $items[] = $this->formatResolvedMemberCompletion($member);
            }
        }

        // ::class magic constant is always available for static access
        if (self::matchesPrefix('class', $prefix)) {
            $items[] = [
                'label' => 'class',
                'kind' => self::KIND_CONSTANT,
                'detail' => 'string (fully qualified class name)',
            ];
        }

        return $items;
    }

    /**
     * Determine minimum visibility for accessing members of target class from enclosing class.
     *
     * @param class-string $targetClassName
     */
    private function getMinVisibilityForAccess(?Stmt\Class_ $enclosingClass, string $targetClassName): Visibility
    {
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

        // Check direct extends in AST
        if (ScopeFinder::resolveExtendsName($enclosingClass) === $targetClassName) {
            return Visibility::Protected;
        }

        // Check deeper inheritance via ClassRepository
        if ($this->classRepository->isSubclassOf(new ClassName($enclosingClassName), new ClassName($targetClassName))) {
            return Visibility::Protected;
        }

        return Visibility::Public;
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getFunctionCompletions(string $prefix, array $ast): array
    {
        $items = [];

        // User-defined functions in current file
        foreach ($ast as $stmt) {
            if ($stmt instanceof Stmt\Function_) {
                $name = $stmt->name->toString();
                if (self::matchesPrefix($name, $prefix)) {
                    $items[] = $this->formatFunctionCompletion($stmt);
                }
            }
        }

        // Built-in functions
        $definedFunctions = get_defined_functions();
        foreach ($definedFunctions['internal'] as $name) {
            if (self::matchesPrefix($name, $prefix)) {
                $items[] = [
                    'label' => $name,
                    'kind' => self::KIND_FUNCTION,
                ];
            }
        }

        // Limit results
        return array_slice($items, 0, 100);
    }

    /**
     * @return CompletionItem
     */
    private function formatResolvedMemberCompletion(ResolvedMember $member): array
    {
        $kind = match (true) {
            $member instanceof ResolvedMethod => self::KIND_METHOD,
            $member instanceof ResolvedProperty => self::KIND_PROPERTY,
            $member instanceof ResolvedConstant => self::KIND_CONSTANT,
            $member instanceof ResolvedEnumCase => self::KIND_ENUM_MEMBER,
            // @codeCoverageIgnoreStart
            default => throw new \LogicException('Unexpected member type: ' . $member::class),
            // @codeCoverageIgnoreEnd
        };

        $item = [
            'label' => $member->getName()->name,
            'kind' => $kind,
            'detail' => $member->format(),
        ];

        $doc = $member->getDocumentation();
        if ($doc !== null) {
            $item['documentation'] = $doc;
        }

        return $item;
    }

    /**
     * @return CompletionItem
     */
    private function formatFunctionCompletion(Stmt\Function_ $func): array
    {
        $funcInfo = FunctionInfo::fromNode($func);

        return self::withDocumentation([
            'label' => $funcInfo->name,
            'kind' => self::KIND_FUNCTION,
            'detail' => $funcInfo->format(),
        ], $funcInfo->docblock);
    }

    /**
     * Resolve a short class name to its FQCN using use statements.
     *
     * @param array<Stmt> $ast
     * @return class-string
     */
    private function resolveClassName(string $shortName, array $ast): string
    {
        $imports = $this->getImports($ast);
        /** @var class-string */
        return $imports[$shortName] ?? $shortName;
    }

    /**
     * Get completions for imported classes (from use statements).
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getImportedClassCompletions(string $prefix, array $ast): array
    {
        $items = [];
        $imports = $this->getImports($ast);

        foreach ($imports as $shortName => $fqcn) {
            if (self::matchesPrefix($shortName, $prefix)) {
                $items[] = [
                    'label' => $shortName,
                    'kind' => self::KIND_CLASS,
                    'detail' => $fqcn,
                ];
            }
        }

        return $items;
    }

    /**
     * Extract all imports from use statements.
     *
     * @param array<Stmt> $ast
     * @return array<string, string> Short name => FQCN
     */
    private function getImports(array $ast): array
    {
        $imports = [];

        foreach (ScopeFinder::iterateTopLevelStatements($ast) as $stmt) {
            $this->extractImportsFromUse($stmt, $imports);
        }

        return $imports;
    }

    /**
     * @param array<string, string> $imports
     */
    private function extractImportsFromUse(Stmt $stmt, array &$imports): void
    {
        if ($stmt instanceof Stmt\Use_) {
            foreach ($stmt->uses as $use) {
                $shortName = $use->alias?->toString() ?? $use->name->getLast();
                $fqcn = $use->name->toString();
                $imports[$shortName] = $fqcn;
            }
        } elseif ($stmt instanceof Stmt\GroupUse) {
            $prefix = $stmt->prefix->toString();
            foreach ($stmt->uses as $use) {
                $shortName = $use->alias?->toString() ?? $use->name->getLast();
                $fqcn = $prefix . '\\' . $use->name->toString();
                $imports[$shortName] = $fqcn;
            }
        }
    }

    /**
     * Get class completions from the workspace symbol index.
     *
     * @param list<SymbolKind> $kinds
     * @return list<CompletionItem>
     */
    private function getIndexedClassCompletions(string $prefix, array $kinds): array
    {
        $symbols = $this->symbolIndex->findByPrefix($prefix, $kinds);
        $items = [];

        foreach ($symbols as $symbol) {
            $items[] = [
                'label' => $symbol->name,
                'kind' => self::KIND_CLASS,
                'detail' => $symbol->fullyQualifiedName,
            ];
        }

        return $items;
    }

    /**
     * Remove duplicate completions, preferring items that appear earlier.
     *
     * @param list<CompletionItem> $items
     * @return list<CompletionItem>
     */
    private function deduplicateCompletions(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['label'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Get completions for type hint positions.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getTypeHintCompletions(string $prefix, array $ast, TypeHintContext $context): array
    {
        $items = [];

        // Types valid in all contexts
        $commonTypes = [
            'string', 'int', 'float', 'bool', 'array', 'object',
            'mixed', 'null', 'callable', 'iterable', 'true', 'false',
        ];

        // Context-specific type validity:
        // | Type   | Property | Parameter | Return |
        // |--------|----------|-----------|--------|
        // | void   | No       | No        | Yes    |
        // | never  | No       | No        | Yes    |
        // | self   | No       | Yes       | Yes    |
        // | static | No       | No        | Yes    |
        // | parent | No       | Yes       | Yes    |
        $builtinTypes = match ($context) {
            TypeHintContext::Property => $commonTypes,
            TypeHintContext::Parameter => [...$commonTypes, 'self', 'parent'],
            TypeHintContext::ReturnType => [...$commonTypes, 'void', 'never', 'self', 'static', 'parent'],
        };

        foreach ($builtinTypes as $type) {
            if (self::matchesPrefix($type, $prefix)) {
                $items[] = [
                    'label' => $type,
                    'kind' => self::KIND_KEYWORD,
                    'detail' => 'builtin type',
                ];
            }
        }

        // Imported classes
        $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));

        // Indexed types (traits are not valid type hints)
        $items = array_merge($items, $this->getIndexedClassCompletions($prefix, [
            SymbolKind::Class_,
            SymbolKind::Interface_,
            SymbolKind::Enum_,
        ]));

        return $this->deduplicateCompletions($items);
    }

    private const KEYWORDS_ALL = [
        // Control flow
        'if', 'else', 'elseif', 'switch', 'case', 'default',
        'while', 'do', 'for', 'foreach', 'break', 'continue',
        'return', 'throw', 'try', 'catch', 'finally',
        // Declarations
        'function', 'class', 'interface', 'trait', 'enum', 'namespace', 'use',
        'extends', 'implements', 'const', 'public', 'protected', 'private',
        'static', 'final', 'abstract', 'readonly',
        // Operators and other
        'new', 'instanceof', 'clone', 'yield', 'match',
        'echo', 'print', 'include', 'include_once', 'require', 'require_once',
        'global', 'unset', 'isset', 'empty', 'list', 'fn',
    ];

    private const KEYWORDS_CLASS_BODY = [
        'public', 'private', 'protected',
        'static', 'final', 'abstract', 'readonly',
        'const', 'function', 'use',
    ];

    private const KEYWORDS_AFTER_VISIBILITY = ['function', 'static', 'readonly', 'const'];

    /**
     * @param list<string> $keywords
     * @return list<CompletionItem>
     */
    private function filterKeywords(array $keywords, string $prefix): array
    {
        $items = [];
        $prefixLower = strtolower($prefix);

        foreach ($keywords as $keyword) {
            if ($prefix === '' || str_starts_with($keyword, $prefixLower)) {
                $items[] = [
                    'label' => $keyword,
                    'kind' => self::KIND_KEYWORD,
                ];
            }
        }

        return $items;
    }

    /**
     * Check if cursor is inside a class/interface/trait/enum body (but not inside a method).
     */
    private function isInClassBody(string $textBeforeCursor): bool
    {
        // Count braces to detect if we're inside a class body
        // This is a heuristic - look for class/interface/trait/enum followed by unbalanced {
        if (preg_match('/(?:class|interface|trait|enum)\s+\w+/', $textBeforeCursor) !== 1) {
            return false;
        }

        // Count brace depth after the class declaration
        $classPos = strrpos($textBeforeCursor, 'class ');
        $interfacePos = strrpos($textBeforeCursor, 'interface ');
        $traitPos = strrpos($textBeforeCursor, 'trait ');
        $enumPos = strrpos($textBeforeCursor, 'enum ');
        $lastClassPos = max(
            $classPos !== false ? $classPos : 0,
            $interfacePos !== false ? $interfacePos : 0,
            $traitPos !== false ? $traitPos : 0,
            $enumPos !== false ? $enumPos : 0,
        );

        $afterClass = substr($textBeforeCursor, $lastClassPos);
        $depth = 0;
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < strlen($afterClass); $i++) {
            $char = $afterClass[$i];

            if ($inString) {
                if ($char === $stringChar && ($i === 0 || $afterClass[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $stringChar = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
        }

        // depth === 1 means we're directly inside the class body (not in a method)
        return $depth === 1;
    }

    /**
     * Get variable completions for the current scope.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getVariableCompletions(string $prefix, array $ast, int $cursorLine): array
    {
        // Find the innermost function/method/closure containing the cursor
        $enclosingScope = $this->findEnclosingScope($ast, $cursorLine);
        if ($enclosingScope === null) {
            return [];
        }

        $inMethod = $enclosingScope instanceof Stmt\ClassMethod;
        $variables = $this->collectScopeVariables($enclosingScope);

        // Build completion items
        $items = [];

        // Add $this if we're in a method
        if ($inMethod && self::matchesPrefix('this', $prefix)) {
            // Use ScopeFinder directly for $this - TypeResolverInterface::resolveVariableType
            // doesn't handle $this (it only checks parameters, use() vars, and assignments)
            $classNode = ScopeFinder::findClassAtLine($ast, $cursorLine);
            $className = $classNode?->namespacedName?->toString() ?? $classNode?->name?->toString();
            $items[] = [
                'label' => '$this',
                'kind' => self::KIND_VARIABLE,
                'detail' => $className ?? 'self',
            ];
        }

        foreach ($variables as $name => $basicType) {
            if (self::matchesPrefix($name, $prefix)) {
                $resolvedType = $this->typeResolver->resolveVariableType($name, $enclosingScope, $cursorLine, $ast);
                $items[] = [
                    'label' => '$' . $name,
                    'kind' => self::KIND_VARIABLE,
                    'detail' => $resolvedType?->format() ?? $basicType,
                ];
            }
        }

        return $items;
    }

    /**
     * Find the innermost function/method/closure containing the given line.
     *
     * @param array<Stmt> $ast
     */
    private function findEnclosingScope(
        array $ast,
        int $cursorLine,
    ): Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null {
        $found = null;

        $visitor = new class ($cursorLine, $found) extends NodeVisitorAbstract {
            /** @var Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null */
            public $found = null;
            private int $cursorLine;

            /**
             * @param Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction|null $found
             */
            public function __construct(int $cursorLine, &$found)
            {
                $this->cursorLine = $cursorLine;
                $this->found = &$found;
            }

            public function enterNode(Node $node): ?int
            {
                if (
                    ($node instanceof Stmt\Function_
                        || $node instanceof Stmt\ClassMethod
                        || $node instanceof Node\Expr\Closure
                        || $node instanceof Node\Expr\ArrowFunction)
                    && ScopeFinder::nodeContainsLine($node, $this->cursorLine)
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
     * Collect variables from a function/method/closure scope.
     *
     * @return array<string, string> Variable name => type
     */
    private function collectScopeVariables(
        Stmt\Function_|Stmt\ClassMethod|Node\Expr\Closure|Node\Expr\ArrowFunction $scope,
    ): array {
        $variables = [];

        // Collect parameters
        foreach ($scope->params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $variables[$param->var->name] = TypeFactory::fromNode($param->type)?->format() ?? 'mixed';
            }
        }

        // Collect use() variables from closures
        if ($scope instanceof Node\Expr\Closure) {
            foreach ($scope->uses as $use) {
                if (is_string($use->var->name)) {
                    $variables[$use->var->name] = 'mixed';
                }
            }
        }

        // Traverse the scope body to find assignments and foreach variables
        $stmts = $scope instanceof Node\Expr\ArrowFunction ? [] : ($scope->stmts ?? []);

        $collector = new class ($variables) extends NodeVisitorAbstract {
            /** @var array<string, string> */
            public array $variables;

            /**
             * @param array<string, string> $variables
             */
            public function __construct(array &$variables)
            {
                $this->variables = &$variables;
            }

            public function enterNode(Node $node): ?int
            {
                // Skip nested function scopes
                if (
                    $node instanceof Stmt\Function_
                    || $node instanceof Stmt\ClassMethod
                    || $node instanceof Node\Expr\Closure
                    || $node instanceof Node\Expr\ArrowFunction
                ) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                // Collect assignments
                if ($node instanceof Node\Expr\Assign) {
                    if ($node->var instanceof Variable && is_string($node->var->name)) {
                        if (!isset($this->variables[$node->var->name])) {
                            $this->variables[$node->var->name] = 'mixed';
                        }
                    }
                }

                // Collect foreach variables
                if ($node instanceof Stmt\Foreach_) {
                    if ($node->valueVar instanceof Variable && is_string($node->valueVar->name)) {
                        $this->variables[$node->valueVar->name] = 'mixed';
                    }
                    if ($node->keyVar instanceof Variable && is_string($node->keyVar->name)) {
                        $this->variables[$node->keyVar->name] = 'mixed';
                    }
                }

                // Collect catch variables
                if ($node instanceof Stmt\Catch_) {
                    if ($node->var !== null && is_string($node->var->name)) {
                        $this->variables[$node->var->name] = 'Exception';
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        return $collector->variables;
    }
}
