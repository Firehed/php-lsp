<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Resolution\CodeResolver;

/**
 * Produces class-name completion items from two sources: classes imported via
 * `use` statements in the current file, and classes in the workspace symbol
 * index. Filtering for each source is driven centrally by
 * {@see ClassCandidateFilter}.
 *
 * Imports are read through {@see CodeResolver} rather than the raw AST, so this
 * source is agnostic to the parsing strategy (and to whether imports were
 * recovered via AST or a text fallback).
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class ClassCandidates
{
    public function __construct(
        private readonly SymbolIndex $symbolIndex,
        private readonly CodeResolver $codeResolver,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(string $prefix, TextDocument $document, ClassCandidateFilter $filter): array
    {
        $items = $this->fromImports($prefix, $document, $filter);
        return array_merge($items, $this->fromIndex($prefix, $filter));
    }

    /**
     * @return list<CompletionItem>
     */
    private function fromImports(string $prefix, TextDocument $document, ClassCandidateFilter $filter): array
    {
        $items = [];
        foreach ($this->codeResolver->getImports($document) as $shortName => $fqcn) {
            if (!PrefixMatcher::matches($shortName, $prefix)) {
                continue;
            }
            /** @var class-string $fqcn */
            if (!$this->passesResolutionFilter(new ClassName($fqcn), $filter)) {
                continue;
            }
            $items[] = CompletionItemFactory::forClass($shortName, $fqcn);
        }

        return $items;
    }

    /**
     * @return list<CompletionItem>
     */
    private function fromIndex(string $prefix, ClassCandidateFilter $filter): array
    {
        $symbols = $this->symbolIndex->findByPrefix($prefix, $this->indexKinds($filter));
        $items = [];

        foreach ($symbols as $symbol) {
            /** @var class-string $fqcn */
            $fqcn = $symbol->fullyQualifiedName;
            if (!$this->passesResolutionFilter(new ClassName($fqcn), $filter)) {
                continue;
            }
            $items[] = CompletionItemFactory::forClass($symbol->name, $fqcn);
        }

        return $items;
    }

    /**
     * The same semantic rule applies to both sources: a type that is invalid in
     * the target position is excluded regardless of whether it came from an
     * import or the index. The index kind pre-filter is an optimization, not a
     * separate policy.
     */
    private function passesResolutionFilter(ClassName $className, ClassCandidateFilter $filter): bool
    {
        return match ($filter) {
            ClassCandidateFilter::Any => true,
            ClassCandidateFilter::Instantiable => $this->codeResolver->isInstantiable($className),
            ClassCandidateFilter::TypeHint => $this->codeResolver->isValidTypeHint($className),
            ClassCandidateFilter::Interface_ => $this->codeResolver->isInterface($className),
            ClassCandidateFilter::ExtendableClass => $this->codeResolver->isExtendableClass($className),
            ClassCandidateFilter::Attribute => $this->codeResolver->isAttribute($className),
        };
    }

    /**
     * @return list<SymbolKind>
     */
    private function indexKinds(ClassCandidateFilter $filter): array
    {
        return match ($filter) {
            ClassCandidateFilter::Any => [
                SymbolKind::Class_,
                SymbolKind::Interface_,
                SymbolKind::Trait_,
                SymbolKind::Enum_,
            ],
            ClassCandidateFilter::Instantiable => [
                SymbolKind::Class_,
                SymbolKind::Enum_,
            ],
            ClassCandidateFilter::TypeHint => [
                SymbolKind::Class_,
                SymbolKind::Interface_,
                SymbolKind::Enum_,
            ],
            ClassCandidateFilter::Interface_ => [
                SymbolKind::Interface_,
            ],
            // A class extends exactly one class; isExtendableClass excludes final ones.
            ClassCandidateFilter::ExtendableClass => [
                SymbolKind::Class_,
            ],
            // Attributes are always classes; isAttribute narrows further per candidate.
            ClassCandidateFilter::Attribute => [
                SymbolKind::Class_,
            ],
        };
    }
}
