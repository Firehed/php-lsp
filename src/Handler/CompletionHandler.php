<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionClassifier;
use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionKind;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManagerInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\TextDocumentPositionParams;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class CompletionHandler implements DocumentFeatureHandlerInterface
{
    use SupportsOwnMethodTrait;

    // The widest position is a bare `\`: every root namespace plus every global
    // class-like. Cap the response and report isIncomplete so the client re-queries
    // as the prefix narrows, rather than shipping thousands of items.
    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly DocumentManagerInterface $documentManager,
        private readonly CodeResolverInterface $codeResolver,
        private readonly SymbolCandidates $instantiableClasses,
        private readonly SymbolCandidates $typeHintClasses,
        private readonly SymbolCandidates $interfaces,
        private readonly SymbolCandidates $extendableClasses,
        private readonly SymbolCandidates $throwables,
        private readonly SymbolCandidates $attributes,
        private readonly SymbolCandidates $traits,
        private readonly SymbolCandidates $useStatementSymbols,
        private readonly SymbolCandidates $anySymbols,
        private readonly KeywordCandidates $allKeywords,
        private readonly KeywordCandidates $classBodyKeywords,
        private readonly KeywordCandidates $afterVisibilityKeywords,
        private readonly KeywordCandidates $expressionKeywords,
        private readonly VariableCandidates $variableCandidates,
        private readonly MemberCandidates $memberCandidates,
        private readonly NamedArgumentCandidates $namedArgumentCandidates,
        private readonly BuiltinTypeCandidates $propertyBuiltins,
        private readonly BuiltinTypeCandidates $parameterBuiltins,
        private readonly BuiltinTypeCandidates $returnTypeBuiltins,
    ) {
    }

    public static function method(): string
    {
        return 'textDocument/completion';
    }

    /**
     * @return array{
     *   isIncomplete: bool,
     *   items: list<CompletionItem>,
     * }|null
     */
    public function handle(Message $message): ?array
    {
        $position = TextDocumentPositionParams::tryFromMessage($message);
        if ($position === null) {
            return null;
        }
        $line = $position->line;
        $character = $position->character;

        $document = $this->documentManager->get($position->uri);
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

        // Get text before cursor to determine completion context
        $textBeforeCursor = $document->textBeforeCursor($line, $character);

        // In interpolated strings, only variables are valid — take the variable
        // source alone rather than filter every source's output after the fact.
        $items = $context === CompletionContext::VariablesOnly
            ? ($this->variableCandidates->find(new CompletionRequest($document, $line, $character)) ?? [])
            : $this->getCompletionItems($textBeforeCursor, $document, $line, $character);

        return $this->capped($items);
    }

    /**
     * Cap the response, ranking before truncating so the cap keeps the best
     * candidates rather than whichever the sources happened to emit first. When
     * truncated, isIncomplete tells the client to re-query as the prefix narrows.
     *
     * The ranking invariant: only items that ask to be ordered carry a sortText —
     * namespace navigation, where a symbol must beat a node. Everything else
     * (members, variables, functions, flat classes) falls back to its label, i.e.
     * an alphabetical cap. That is deliberate: it is deterministic, and by ASCII a
     * PascalCase project symbol (`MyClass`) sorts ahead of a lowercase builtin
     * (`mysqli_connect`), so a user's own types survive the cap over the standard
     * library when a broad prefix matches both. The trade is that a >limit member
     * list is alphabetised rather than kept in resolution order — benign, and rare.
     *
     * @param list<CompletionItem> $items
     * @return array{isIncomplete: bool, items: list<CompletionItem>}
     */
    private function capped(array $items): array
    {
        if (count($items) <= self::RESULT_LIMIT) {
            return ['isIncomplete' => false, 'items' => $items];
        }

        usort(
            $items,
            static fn(array $a, array $b): int
                => ($a['sortText'] ?? $a['label']) <=> ($b['sortText'] ?? $b['label']),
        );

        return [
            'isIncomplete' => true,
            'items' => array_slice($items, 0, self::RESULT_LIMIT),
        ];
    }

    /**
     * @return list<CompletionItem>
     */
    private function getCompletionItems(
        string $textBeforeCursor,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        // Member/static access (after -> or ::)
        $memberItems = $this->memberCandidates->find(new CompletionRequest($document, $line, $character));
        if ($memberItems !== null) {
            return $memberItems;
        }

        // Inside a call context, offer named arguments + variables
        $callContext = $this->codeResolver->getCallContext($document, $line, $character);
        if ($callContext !== null) {
            $items = $this->namedArgumentCandidates->find(new CompletionRequest($document, $line, $character)) ?? [];

            // Also offer variables - filter by prefix if cursor is on one
            $items = array_merge(
                $items,
                $this->variableCandidates->find(new CompletionRequest($document, $line, $character)) ?? [],
            );

            $expressionPrefix = CompletionClassifier::callExpressionPrefix($textBeforeCursor);
            if ($expressionPrefix !== null) {
                $items = array_merge($items, array_map(
                    static fn(array $item): array => ['sortText' => '"' . $item['label']] + $item,
                    $this->expressionKeywords->find(new CompletionRequest($document, $line, $character)) ?? [],
                ));
                $items = array_merge(
                    $items,
                    $this->anySymbols->find(new CompletionRequest($document, $line, $character)) ?? [],
                );
            }

            return $this->deduplicateCompletions($items);
        }

        // Remaining positions are classified by text before the cursor. Detection
        // stays text-based so completion keeps working on mid-edit, unparseable code.
        $classification = CompletionClassifier::classify($textBeforeCursor);
        $prefix = $classification->prefix;

        return match ($classification->kind) {
            CompletionKind::Variable => $this->variableCandidates->find(
                new CompletionRequest($document, $line, $character),
            ) ?? [],
            CompletionKind::New_ => $this->classItemsFrom($this->instantiableClasses, $document, $line, $character),
            CompletionKind::AfterVisibility => $this->getAfterVisibilityCompletions(
                $prefix,
                $document,
                $line,
                $character,
            ),
            CompletionKind::ReturnType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                $line,
                $character,
                TypeHintContext::ReturnType,
            ),
            CompletionKind::PropertyType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                $line,
                $character,
                TypeHintContext::Property,
            ),
            CompletionKind::ParameterType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                $line,
                $character,
                TypeHintContext::Parameter,
            ),
            CompletionKind::InterfaceList => $this->classItemsFrom($this->interfaces, $document, $line, $character),
            CompletionKind::ExtendableClass => $this->classItemsFrom(
                $this->extendableClasses,
                $document,
                $line,
                $character,
            ),
            CompletionKind::Throwable => $this->classItemsFrom($this->throwables, $document, $line, $character),
            CompletionKind::Attribute => $this->classItemsFrom($this->attributes, $document, $line, $character),
            CompletionKind::Instanceof_ => $this->classItemsFrom($this->typeHintClasses, $document, $line, $character),
            CompletionKind::Use_ => $this->getUseCompletions($prefix, $document, $line, $character),
            CompletionKind::ClassBody => $this->classBodyKeywords->find(
                new CompletionRequest($document, $line, $character),
            ) ?? [],
            CompletionKind::Expression => $this->getExpressionCompletions($prefix, $document, $line, $character),
            CompletionKind::None => [],
        };
    }

    /**
     * @return list<CompletionItem>
     */
    private function classItemsFrom(
        SymbolCandidates $source,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        return $this->deduplicateCompletions(
            $source->find(new CompletionRequest($document, $line, $character)) ?? [],
        );
    }

    /**
     * Suggest completions for a `use` keyword. The classifier sees only the
     * current line, so a multi-line class body `use` and a top-level `use`
     * import are indistinguishable there; the structural checks here read the
     * whole document to disambiguate.
     *
     * @return list<CompletionItem>
     */
    private function getUseCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        $offset = $document->offsetAt($line, $character);
        $content = $document->getContent();
        if (ContextDetector::isInsideClassBody($content, $offset)) {
            return $this->classItemsFrom($this->traits, $document, $line, $character);
        }
        if (ContextDetector::isClosureUse($content, $offset)) {
            return $this->variableCandidates->find(new CompletionRequest($document, $line, $character)) ?? [];
        }

        return $this->useStatementSymbols->forUseStatement($prefix, $line, $character);
    }

    /**
     * Suggest member keywords or a property type after a visibility keyword.
     *
     * @return list<CompletionItem>
     */
    private function getAfterVisibilityCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        $items = $this->afterVisibilityKeywords->find(new CompletionRequest($document, $line, $character)) ?? [];
        $items = array_merge(
            $items,
            $this->getTypeHintCompletions($prefix, $document, $line, $character, TypeHintContext::Property),
        );
        return $this->deduplicateCompletions($items);
    }

    /**
     * Suggest keywords, functions, and class names at the start of an expression.
     *
     * @return list<CompletionItem>
     */
    private function getExpressionCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
    ): array {
        $items = $this->allKeywords->find(new CompletionRequest($document, $line, $character)) ?? [];
        $items = array_merge(
            $items,
            $this->anySymbols->find(new CompletionRequest($document, $line, $character)) ?? [],
        );
        return $this->deduplicateCompletions($items);
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
     * @return list<CompletionItem>
     */
    private function getTypeHintCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
        TypeHintContext $context,
    ): array {
        $builtins = match ($context) {
            TypeHintContext::Property => $this->propertyBuiltins,
            TypeHintContext::Parameter => $this->parameterBuiltins,
            TypeHintContext::ReturnType => $this->returnTypeBuiltins,
        };
        $items = $builtins->find(new CompletionRequest($document, $line, $character)) ?? [];

        // Class-likes valid as type hints (traits excluded), plus navigation into
        // absolute namespaces (`function f(\Ps`), via the shared class path.
        $items = array_merge(
            $items,
            $this->classItemsFrom($this->typeHintClasses, $document, $line, $character),
        );

        return $this->deduplicateCompletions($items);
    }
}
