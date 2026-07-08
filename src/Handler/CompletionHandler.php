<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\CompletionClassifier;
use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\CompletionKind;
use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberFilter;
use Firehed\PhpLsp\Resolution\ResolvedConstant;
use Firehed\PhpLsp\Resolution\ResolvedEnumCase;
use Firehed\PhpLsp\Resolution\ResolvedMember;
use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node\Stmt;

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
    private const KIND_FIELD = 5;
    private const KIND_VARIABLE = 6;
    private const KIND_CLASS = 7;
    private const KIND_PROPERTY = 10;
    private const KIND_KEYWORD = 14;
    private const KIND_ENUM_MEMBER = 20;
    private const KIND_CONSTANT = 21;

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
        private readonly CodeResolver $codeResolver,
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

        // Determine completion context
        $offset = $document->offsetAt($line, $character);
        $context = ContextDetector::getContext($document->getContent(), $offset);
        if ($context === CompletionContext::None) {
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

        $items = $this->getCompletionItems($textBeforeCursor, $document, $ast, $line, $character);

        // In interpolated strings, only variable completions are valid
        if ($context === CompletionContext::VariablesOnly) {
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => ($item['kind'] ?? 0) === self::KIND_VARIABLE,
            ));
        }

        return [
            'isIncomplete' => false,
            'items' => $items,
        ];
    }

    /**
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getCompletionItems(
        string $textBeforeCursor,
        TextDocument $document,
        array $ast,
        int $line,
        int $character,
    ): array {
        // Member/static access via SymbolResolver
        $memberContext = $this->codeResolver->getMemberAccessContext($document, $line, $character);
        if ($memberContext !== null) {
            return $this->handleMemberAccessContext($memberContext, $document);
        }

        // Inside a call context, offer named arguments + variables
        $callContext = $this->codeResolver->getCallContext($document, $line, $character);
        if ($callContext !== null) {
            $namedArgPrefix = $this->extractNamedArgPrefix($textBeforeCursor);
            $items = $this->getNamedArgumentCompletions($callContext, $namedArgPrefix);

            // Also offer variables - filter by prefix if cursor is on one
            $varPrefix = '';
            if (preg_match('/\$(\w*)$/', $textBeforeCursor, $matches) === 1) {
                $varPrefix = $matches[1];
            }
            $items = array_merge($items, $this->getVariableCompletions($varPrefix, $document, $line, $character));

            // After named arg colon (value position), also offer expression keywords and classes
            if (preg_match('/\w+:\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
                $prefix = $matches[1];
                $items = array_merge($items, $this->filterKeywords(self::KEYWORDS_EXPRESSION, $prefix));
                $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast));
                $items = array_merge($items, $this->getIndexedClassCompletions($prefix, [
                    SymbolKind::Class_,
                    SymbolKind::Interface_,
                    SymbolKind::Trait_,
                    SymbolKind::Enum_,
                ]));
            }

            return $this->deduplicateCompletions($items);
        }

        // Remaining positions are classified by text before the cursor. Detection
        // stays text-based so completion keeps working on mid-edit, unparseable code.
        $classification = CompletionClassifier::classify($textBeforeCursor);
        $prefix = $classification->prefix;

        return match ($classification->kind) {
            CompletionKind::Variable => $this->getVariableCompletions($prefix, $document, $line, $character),
            CompletionKind::New_ => $this->getNewCompletions($prefix, $ast),
            CompletionKind::AfterVisibility => $this->getAfterVisibilityCompletions($prefix, $ast),
            CompletionKind::ReturnType => $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::ReturnType),
            CompletionKind::PropertyType => $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property),
            CompletionKind::ParameterType => $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Parameter),
            CompletionKind::ClassBody => $this->filterKeywords(self::KEYWORDS_CLASS_BODY, $prefix),
            CompletionKind::Expression => $this->getExpressionCompletions($prefix, $ast),
            CompletionKind::None => [],
        };
    }

    /**
     * Suggest imported classes and indexed instantiable types after `new`.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getNewCompletions(string $prefix, array $ast): array
    {
        $items = $this->getImportedClassCompletions($prefix, $ast, instantiableOnly: true);
        $indexedItems = $this->getIndexedClassCompletions(
            $prefix,
            [SymbolKind::Class_, SymbolKind::Enum_],
            instantiableOnly: true,
        );
        $items = array_merge($items, $indexedItems);
        return $this->deduplicateCompletions($items);
    }

    /**
     * Suggest member keywords or a property type after a visibility keyword.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getAfterVisibilityCompletions(string $prefix, array $ast): array
    {
        $items = $this->filterKeywords(self::KEYWORDS_AFTER_VISIBILITY, $prefix);
        $items = array_merge($items, $this->getTypeHintCompletions($prefix, $ast, TypeHintContext::Property));
        return $this->deduplicateCompletions($items);
    }

    /**
     * Suggest keywords, functions, and class names at the start of an expression.
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getExpressionCompletions(string $prefix, array $ast): array
    {
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

    /**
     * @return list<CompletionItem>
     */
    private function handleMemberAccessContext(MemberAccessContext $context, TextDocument $document): array
    {
        $items = [];
        $filter = match ($context->kind) {
            MemberAccessKind::Instance => MemberFilter::Instance,
            MemberAccessKind::Static => MemberFilter::Static,
            MemberAccessKind::Parent => MemberFilter::All,
        };

        $members = $this->codeResolver->getAccessibleMembers(
            $document,
            $context->type,
            $context->minVisibility,
            $filter,
        );

        foreach ($members as $member) {
            if ($context->kind === MemberAccessKind::Parent && !$member instanceof ResolvedMethod) {
                continue;
            }
            if (self::matchesPrefix($member->getName()->name, $context->prefix)) {
                $items[] = $this->formatResolvedMemberCompletion($member);
            }
        }

        if ($context->kind === MemberAccessKind::Static && self::matchesPrefix('class', $context->prefix)) {
            $items[] = [
                'label' => 'class',
                'kind' => self::KIND_CONSTANT,
                'detail' => 'string (fully qualified class name)',
            ];
        }

        return $items;
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
     * Get completions for imported classes (from use statements).
     *
     * @param array<Stmt> $ast
     * @return list<CompletionItem>
     */
    private function getImportedClassCompletions(
        string $prefix,
        array $ast,
        bool $instantiableOnly = false,
        bool $typeHintContext = false,
    ): array {
        $items = [];
        $imports = $this->getImports($ast);

        foreach ($imports as $shortName => $fqcn) {
            if (!self::matchesPrefix($shortName, $prefix)) {
                continue;
            }
            /** @var class-string $fqcn */
            if ($instantiableOnly && !$this->codeResolver->isInstantiable(new ClassName($fqcn))) {
                continue;
            }
            if ($typeHintContext && !$this->codeResolver->isValidTypeHint(new ClassName($fqcn))) {
                continue;
            }
            $items[] = [
                'label' => $shortName,
                'kind' => self::KIND_CLASS,
                'detail' => $fqcn,
            ];
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
    private function getIndexedClassCompletions(
        string $prefix,
        array $kinds,
        bool $instantiableOnly = false,
    ): array {
        $symbols = $this->symbolIndex->findByPrefix($prefix, $kinds);
        $items = [];

        foreach ($symbols as $symbol) {
            /** @var class-string $fqcn */
            $fqcn = $symbol->fullyQualifiedName;
            if ($instantiableOnly && !$this->codeResolver->isInstantiable(new ClassName($fqcn))) {
                continue;
            }
            $items[] = [
                'label' => $symbol->name,
                'kind' => self::KIND_CLASS,
                'detail' => $fqcn,
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

        // Imported classes (traits are not valid type hints)
        $items = array_merge($items, $this->getImportedClassCompletions($prefix, $ast, typeHintContext: true));

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

    // Keywords valid at the start of an expression (e.g., after `name: ` in named args)
    private const KEYWORDS_EXPRESSION = [
        'new', 'clone', 'yield', 'match', 'fn',
        'isset', 'empty', 'list',
        'true', 'false', 'null',
    ];

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
     * Get variable completions for the current scope.
     *
     * @return list<CompletionItem>
     */
    private function getVariableCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        $variables = $this->codeResolver->getVariablesInScope($document, $line, $character);

        $items = [];
        foreach ($variables as $variable) {
            if (self::matchesPrefix($variable->getName(), $prefix)) {
                $items[] = [
                    'label' => '$' . $variable->getName(),
                    'kind' => self::KIND_VARIABLE,
                    'detail' => $variable->getType()?->format() ?? 'mixed',
                ];
            }
        }

        return $items;
    }

    /**
     * Extract the prefix for named argument completion from text before cursor.
     */
    private function extractNamedArgPrefix(string $textBeforeCursor): string
    {
        if (preg_match('/[(,]\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Get named argument completions for a function/method call.
     *
     * @return list<CompletionItem>
     */
    private function getNamedArgumentCompletions(
        \Firehed\PhpLsp\Resolution\CallContext $callContext,
        string $prefix,
    ): array {
        $callable = $callContext->callable;
        $params = $callable->getParameters();
        $usedNames = $callContext->usedParameterNames;
        $positionallyFilledCount = $callContext->positionallyFilledCount;

        $items = [];
        foreach ($params as $param) {
            // Skip parameters already used as named arguments
            if (in_array($param->name, $usedNames, true)) {
                continue;
            }

            // Skip parameters filled positionally (before the first named arg)
            if ($param->position < $positionallyFilledCount) {
                continue;
            }

            // Skip variadic parameters as named arguments (they use array syntax instead)
            if ($param->isVariadic) {
                continue;
            }

            // Match prefix
            if (!self::matchesPrefix($param->name, $prefix)) {
                continue;
            }

            $items[] = [
                'label' => $param->name . ':',
                'kind' => self::KIND_FIELD,
                'detail' => $param->format(),
            ];
        }

        return $items;
    }
}
