<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\ClassCandidateFilter;
use Firehed\PhpLsp\Completion\ClassCandidates;
use Firehed\PhpLsp\Completion\CompletionClassifier;
use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Completion\CompletionKind;
use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Completion\FunctionCandidates;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\KeywordGroup;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\NamespaceCandidates;
use Firehed\PhpLsp\Completion\TypeHintContext;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class CompletionHandler implements HandlerInterface
{
    // The widest position is a bare `\`: every root namespace plus every global
    // class-like. Cap the response and report isIncomplete so the client re-queries
    // as the prefix narrows, rather than shipping thousands of items.
    private const RESULT_LIMIT = 100;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
        private readonly ClassCandidates $classCandidates,
        private readonly NamespaceCandidates $namespaceCandidates,
        private readonly FunctionCandidates $functionCandidates,
        private readonly KeywordCandidates $keywordCandidates,
        private readonly VariableCandidates $variableCandidates,
        private readonly MemberCandidates $memberCandidates,
        private readonly NamedArgumentCandidates $namedArgumentCandidates,
        private readonly BuiltinTypeCandidates $builtinTypeCandidates,
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

        // Get text before cursor to determine completion context
        $lineText = $document->getLine($line);
        $textBeforeCursor = substr($lineText, 0, $character);

        $items = $this->getCompletionItems($textBeforeCursor, $document, $line, $character);

        // In interpolated strings, only variable completions are valid
        if ($context === CompletionContext::VariablesOnly) {
            $items = array_values(array_filter(
                $items,
                static fn(array $item): bool => ($item['kind'] ?? 0) === CompletionItemKind::Variable->value,
            ));
        }

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
        $memberItems = $this->memberCandidates->find($document, $line, $character);
        if ($memberItems !== null) {
            return $memberItems;
        }

        // Inside a call context, offer named arguments + variables
        $callContext = $this->codeResolver->getCallContext($document, $line, $character);
        if ($callContext !== null) {
            $items = $this->namedArgumentCandidates->find($callContext, $textBeforeCursor);

            // Also offer variables - filter by prefix if cursor is on one
            $varPrefix = '';
            if (preg_match('/\$(\w*)$/', $textBeforeCursor, $matches) === 1) {
                $varPrefix = $matches[1];
            }
            $items = array_merge(
                $items,
                $this->variableCandidates->find($varPrefix, $document, $line, $character),
            );

            // After named arg colon (value position), also offer expression keywords and classes
            if (preg_match('/\w+:\s*(\w*)$/', $textBeforeCursor, $matches) === 1) {
                $prefix = $matches[1];
                $items = array_merge($items, $this->keywordCandidates->find($prefix, KeywordGroup::Expression));
                $items = array_merge(
                    $items,
                    $this->classCandidates->find($prefix, $document, $line, $character, ClassCandidateFilter::Any),
                );
            }

            return $this->deduplicateCompletions($items);
        }

        // Remaining positions are classified by text before the cursor. Detection
        // stays text-based so completion keeps working on mid-edit, unparseable code.
        $classification = CompletionClassifier::classify($textBeforeCursor);
        $prefix = $classification->prefix;

        return match ($classification->kind) {
            CompletionKind::Variable => $this->variableCandidates->find($prefix, $document, $line, $character),
            CompletionKind::New_ => $this->getNewCompletions($prefix, $document, $line, $character),
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
            CompletionKind::InterfaceList => $this->getClassCompletions(
                $prefix,
                $document,
                $line,
                $character,
                ClassCandidateFilter::Interface_,
            ),
            CompletionKind::ExtendableClass => $this->getClassCompletions(
                $prefix,
                $document,
                $line,
                $character,
                ClassCandidateFilter::ExtendableClass,
            ),
            CompletionKind::Throwable => $this->getClassCompletions(
                $prefix,
                $document,
                $line,
                $character,
                ClassCandidateFilter::Throwable,
            ),
            CompletionKind::Attribute => $this->getClassCompletions(
                $prefix,
                $document,
                $line,
                $character,
                ClassCandidateFilter::Attribute,
            ),
            CompletionKind::Instanceof_ => $this->getClassCompletions(
                $prefix,
                $document,
                $line,
                $character,
                ClassCandidateFilter::TypeHint,
            ),
            CompletionKind::Use_ => $this->getUseCompletions($prefix, $line, $character),
            CompletionKind::ClassBody => $this->keywordCandidates->find($prefix, KeywordGroup::ClassBody),
            CompletionKind::Expression => $this->getExpressionCompletions($prefix, $document, $line, $character),
            CompletionKind::None => [],
        };
    }

    /**
     * Suggest instantiable class names after `new`.
     *
     * @return list<CompletionItem>
     */
    private function getNewCompletions(string $prefix, TextDocument $document, int $line, int $character): array
    {
        return $this->getClassCompletions($prefix, $document, $line, $character, ClassCandidateFilter::Instantiable);
    }

    /**
     * Class-name candidates valid for a position, from the workspace index and
     * imports, plus namespace navigation from the catalog. Every class position
     * routes through here, so navigation is offered consistently and filtered by the
     * same predicate everywhere. Name resolution (absolute vs import vs
     * current-namespace) lives in {@see NamespaceCandidates::navigate()}, not here.
     *
     * @return list<CompletionItem>
     */
    private function getClassCompletions(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
    ): array {
        $items = array_merge(
            $this->classCandidates->find($prefix, $document, $line, $character, $filter),
            $this->namespaceCandidates->navigate(
                $prefix,
                $this->codeResolver->getNameContext($document, $line),
                $line,
                $character,
                $filter,
            ),
        );

        return $this->deduplicateCompletions($items);
    }

    /**
     * Suggest namespaces and class-likes for a `use` import statement. A `use` name
     * is fully qualified — resolved from the global namespace regardless of the
     * file's own namespace or imports — so navigation walks the tree absolutely
     * rather than through the current-namespace/import resolution the other class
     * positions use. Any class-like is importable, so no position filter narrows it.
     *
     * @return list<CompletionItem>
     */
    private function getUseCompletions(string $prefix, int $line, int $character): array
    {
        return $this->namespaceCandidates->useStatement(
            $prefix,
            $line,
            $character,
            ClassCandidateFilter::Any,
        );
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
        $items = $this->keywordCandidates->find($prefix, KeywordGroup::AfterVisibility);
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
        $items = $this->keywordCandidates->find($prefix, KeywordGroup::All);
        $items = array_merge($items, $this->functionCandidates->find($prefix, $document));
        $items = array_merge(
            $items,
            $this->classCandidates->find($prefix, $document, $line, $character, ClassCandidateFilter::Any),
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
        $items = $this->builtinTypeCandidates->find($prefix, $context);

        // Class-likes valid as type hints (traits excluded), plus navigation into
        // absolute namespaces (`function f(\Ps`), via the shared class path.
        $items = array_merge(
            $items,
            $this->getClassCompletions($prefix, $document, $line, $character, ClassCandidateFilter::TypeHint),
        );

        return $this->deduplicateCompletions($items);
    }
}
