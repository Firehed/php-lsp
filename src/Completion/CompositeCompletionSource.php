<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Resolution\CodeResolverInterface;

/**
 * The one completion source the handler consumes. It owns the whole dispatch:
 * which sources apply at which positions, which sources short-circuit, and
 * how their items are merged, deduplicated, and sortText-normalized.
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class CompositeCompletionSource implements CompletionSourceInterface
{
    public function __construct(
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

    public function find(CompletionRequest $request): ?array
    {
        $offset = $request->document->offsetAt($request->line, $request->character);
        $context = ContextDetector::getContext($request->document->getContent(), $offset);
        if ($context === CompletionContext::None) {
            return [];
        }

        // In interpolated strings, only variables are valid — take the variable
        // source alone rather than filter every source's output after the fact.
        if ($context === CompletionContext::VariablesOnly) {
            return $this->variableCandidates->find($request) ?? [];
        }

        // Member/static access (after -> or ::) short-circuits — the source owns
        // the whole case and other sources must not add items.
        $memberItems = $this->memberCandidates->find($request);
        if ($memberItems !== null) {
            return $memberItems;
        }

        // Inside a call context, offer named arguments + variables + expressions.
        if ($this->codeResolver->getCallContext($request->document, $request->line, $request->character) !== null) {
            return $this->deduplicate($this->callContextItems($request));
        }

        return $this->classifiedItems($request);
    }

    /**
     * @return list<CompletionItem>
     */
    private function callContextItems(CompletionRequest $request): array
    {
        $items = array_merge(
            $this->namedArgumentCandidates->find($request) ?? [],
            $this->variableCandidates->find($request) ?? [],
        );

        $expressionPrefix = CompletionClassifier::callExpressionPrefix($request->textBeforeCursor());
        if ($expressionPrefix === null) {
            return $items;
        }

        // Expression keywords compete with symbols here; prepend `"` to their
        // sortText so a project symbol beats a keyword on the same prefix.
        $items = array_merge($items, array_map(
            static fn(array $item): array => ['sortText' => '"' . $item['label']] + $item,
            $this->expressionKeywords->find($request) ?? [],
        ));

        return array_merge($items, $this->anySymbols->find($request) ?? []);
    }

    /**
     * @return list<CompletionItem>
     */
    private function classifiedItems(CompletionRequest $request): array
    {
        $classification = $request->classification();

        return match ($classification->kind) {
            CompletionKind::Variable => $this->variableCandidates->find($request) ?? [],
            CompletionKind::New_ => $this->deduplicate($this->instantiableClasses->find($request) ?? []),
            CompletionKind::AfterVisibility => $this->afterVisibilityItems($request),
            CompletionKind::ReturnType => $this->typeHintItems($this->returnTypeBuiltins, $request),
            CompletionKind::PropertyType => $this->typeHintItems($this->propertyBuiltins, $request),
            CompletionKind::ParameterType => $this->typeHintItems($this->parameterBuiltins, $request),
            CompletionKind::InterfaceList => $this->deduplicate($this->interfaces->find($request) ?? []),
            CompletionKind::ExtendableClass => $this->deduplicate($this->extendableClasses->find($request) ?? []),
            CompletionKind::Throwable => $this->deduplicate($this->throwables->find($request) ?? []),
            CompletionKind::Attribute => $this->deduplicate($this->attributes->find($request) ?? []),
            CompletionKind::Instanceof_ => $this->deduplicate($this->typeHintClasses->find($request) ?? []),
            CompletionKind::Use_ => $this->useStatementItems($request),
            CompletionKind::ClassBody => $this->classBodyKeywords->find($request) ?? [],
            CompletionKind::Expression => $this->expressionItems($request),
            CompletionKind::None => [],
        };
    }

    /**
     * @return list<CompletionItem>
     */
    private function afterVisibilityItems(CompletionRequest $request): array
    {
        return $this->deduplicate(array_merge(
            $this->afterVisibilityKeywords->find($request) ?? [],
            $this->typeHintItems($this->propertyBuiltins, $request),
        ));
    }

    /**
     * @return list<CompletionItem>
     */
    private function typeHintItems(BuiltinTypeCandidates $builtins, CompletionRequest $request): array
    {
        return $this->deduplicate(array_merge(
            $builtins->find($request) ?? [],
            $this->typeHintClasses->find($request) ?? [],
        ));
    }

    /**
     * Suggest completions for a `use` keyword. The classifier sees only the
     * current line, so a multi-line class body `use` and a top-level `use`
     * import are indistinguishable there; the structural checks here read the
     * whole document to disambiguate.
     *
     * @return list<CompletionItem>
     */
    private function useStatementItems(CompletionRequest $request): array
    {
        $offset = $request->document->offsetAt($request->line, $request->character);
        $content = $request->document->getContent();
        if (ContextDetector::isInsideClassBody($content, $offset)) {
            return $this->deduplicate($this->traits->find($request) ?? []);
        }
        if (ContextDetector::isClosureUse($content, $offset)) {
            return $this->variableCandidates->find($request) ?? [];
        }

        return $this->useStatementSymbols->forUseStatement(
            $request->classification()->prefix,
            $request->line,
            $request->character,
        );
    }

    /**
     * @return list<CompletionItem>
     */
    private function expressionItems(CompletionRequest $request): array
    {
        return $this->deduplicate(array_merge(
            $this->allKeywords->find($request) ?? [],
            $this->anySymbols->find($request) ?? [],
        ));
    }

    /**
     * Remove duplicate completions, preferring items that appear earlier.
     *
     * @param list<CompletionItem> $items
     * @return list<CompletionItem>
     */
    private function deduplicate(array $items): array
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
}
