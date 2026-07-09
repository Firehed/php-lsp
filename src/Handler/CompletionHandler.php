<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

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
use Firehed\PhpLsp\Completion\PrefixMatcher;
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
    private static function matchesPrefix(string $name, string $prefix): bool
    {
        return PrefixMatcher::matches($name, $prefix);
    }

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly CodeResolver $codeResolver,
        private readonly ClassCandidates $classCandidates,
        private readonly FunctionCandidates $functionCandidates,
        private readonly KeywordCandidates $keywordCandidates,
        private readonly VariableCandidates $variableCandidates,
        private readonly MemberCandidates $memberCandidates,
        private readonly NamedArgumentCandidates $namedArgumentCandidates,
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

        return [
            'isIncomplete' => false,
            'items' => $items,
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
                    $this->classCandidates->find($prefix, $document, ClassCandidateFilter::Any),
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
            CompletionKind::New_ => $this->getNewCompletions($prefix, $document),
            CompletionKind::AfterVisibility => $this->getAfterVisibilityCompletions($prefix, $document),
            CompletionKind::ReturnType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                TypeHintContext::ReturnType,
            ),
            CompletionKind::PropertyType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                TypeHintContext::Property,
            ),
            CompletionKind::ParameterType => $this->getTypeHintCompletions(
                $prefix,
                $document,
                TypeHintContext::Parameter,
            ),
            CompletionKind::ClassBody => $this->keywordCandidates->find($prefix, KeywordGroup::ClassBody),
            CompletionKind::Expression => $this->getExpressionCompletions($prefix, $document),
            CompletionKind::None => [],
        };
    }

    /**
     * Suggest instantiable class names after `new`.
     *
     * @return list<CompletionItem>
     */
    private function getNewCompletions(string $prefix, TextDocument $document): array
    {
        return $this->deduplicateCompletions(
            $this->classCandidates->find($prefix, $document, ClassCandidateFilter::Instantiable),
        );
    }

    /**
     * Suggest member keywords or a property type after a visibility keyword.
     *
     * @return list<CompletionItem>
     */
    private function getAfterVisibilityCompletions(string $prefix, TextDocument $document): array
    {
        $items = $this->keywordCandidates->find($prefix, KeywordGroup::AfterVisibility);
        $items = array_merge($items, $this->getTypeHintCompletions($prefix, $document, TypeHintContext::Property));
        return $this->deduplicateCompletions($items);
    }

    /**
     * Suggest keywords, functions, and class names at the start of an expression.
     *
     * @return list<CompletionItem>
     */
    private function getExpressionCompletions(string $prefix, TextDocument $document): array
    {
        $items = $this->keywordCandidates->find($prefix, KeywordGroup::All);
        $items = array_merge($items, $this->functionCandidates->find($prefix, $document));
        $items = array_merge($items, $this->classCandidates->find($prefix, $document, ClassCandidateFilter::Any));
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
    private function getTypeHintCompletions(string $prefix, TextDocument $document, TypeHintContext $context): array
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
                $items[] = CompletionItemFactory::forBuiltinType($type);
            }
        }

        // Class-likes valid as type hints (traits excluded)
        $items = array_merge($items, $this->classCandidates->find($prefix, $document, ClassCandidateFilter::TypeHint));

        return $this->deduplicateCompletions($items);
    }
}
