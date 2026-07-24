<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Resolution\ReferenceResolver;

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
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    /**
     * @return list<CompletionItem>
     */
    public function find(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
        ClassCandidateFilter $filter,
    ): array {
        $context = $this->codeResolver->getNameContext($document, $line);
        // What a selected item replaces: the token the cursor sits at the end of,
        // sized in the negotiated encoding so a multibyte prefix is not mis-measured
        // against the wire column (RFC 1 §4.9).
        $replaceRange = Range::forPrefix(
            $line,
            $character,
            $prefix,
            $this->capabilities->getSessionCapabilities()->positionEncoding,
        );

        $items = $this->fromImports($prefix, $document, $filter, $replaceRange);
        return array_merge($items, $this->fromIndex($prefix, $filter, $context, $replaceRange));
    }

    /**
     * @return list<CompletionItem>
     */
    private function fromImports(
        string $prefix,
        TextDocument $document,
        ClassCandidateFilter $filter,
        Range $replaceRange,
    ): array {
        $items = [];
        foreach ($this->codeResolver->getImports($document) as $shortName => $fqcn) {
            if (!PrefixMatcher::matches($shortName, $prefix)) {
                continue;
            }
            /** @var class-string $fqcn */
            if (!$filter->accepts(new ClassName($fqcn), $this->codeResolver)) {
                continue;
            }
            $items[] = CompletionItemFactory::forClass($shortName, $fqcn, $replaceRange);
        }

        return $items;
    }

    /**
     * @return list<CompletionItem>
     */
    private function fromIndex(
        string $prefix,
        ClassCandidateFilter $filter,
        NameContext $context,
        Range $replaceRange,
    ): array {
        $symbols = $this->symbolIndex->findByPrefix($prefix, $this->indexKinds($filter));
        $items = [];

        foreach ($symbols as $symbol) {
            /** @var class-string $fqcn */
            $fqcn = $symbol->fullyQualifiedName;
            if (!$filter->accepts(new ClassName($fqcn), $this->codeResolver)) {
                continue;
            }
            // The index is keyed by short name, but a class in another namespace
            // may need a qualified reference — or none may reach it at all. Offer
            // it only where it resolves, and label it with the reference that
            // does, so selecting it inserts a name that resolves back to it.
            $reference = ReferenceResolver::resolve($fqcn, NameKind::ClassLike, $context);
            if (!$reference->isReachable()) {
                continue;
            }
            $items[] = CompletionItemFactory::forClass($reference->text, $fqcn, $replaceRange);
        }

        return $items;
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
            // A catch clause accepts classes and interfaces; isThrowable narrows to
            // Throwable subtypes.
            ClassCandidateFilter::Throwable => [
                SymbolKind::Class_,
                SymbolKind::Interface_,
            ],
            // Attributes are always classes; isAttribute narrows further per candidate.
            ClassCandidateFilter::Attribute => [
                SymbolKind::Class_,
            ],
        };
    }
}
