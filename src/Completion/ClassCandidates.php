<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ReferenceResolver;

/**
 * Produces class-name completion items from two sources: classes imported via
 * `use` statements in the current file, and class-likes reached through the
 * {@see SymbolSource} knowledge seam (RFC 1 §4.2). Filtering for each source is
 * driven centrally by {@see ClassCandidateFilter}.
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
        private readonly SymbolSource $symbolSource,
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

        $items = $this->fromImports($prefix, $context, $filter, $replaceRange);
        return array_merge($items, $this->fromIndex($prefix, $filter, $context, $replaceRange));
    }

    /**
     * @return list<CompletionItem>
     */
    private function fromImports(
        string $prefix,
        NameContext $context,
        ClassCandidateFilter $filter,
        Range $replaceRange,
    ): array {
        $items = [];
        foreach ($context->classImports as $shortName => $fqcn) {
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
        // The seam searches the whole class-like namespace (RFC 1 §4.2); which of
        // those kinds this position admits stays the consumer's decision, applied
        // here against each result's own kind. That keeps the coarse narrowing the
        // index query used to do — and does not defer it to `accepts`, whose
        // repository lookup cannot vouch for a symbol whose declaration it cannot
        // reach (Plan 0002 §5.5: identical behavior).
        $kinds = $this->indexKinds($filter);
        $symbols = $this->symbolSource->search($prefix, NameKind::ClassLike);
        $items = [];

        foreach ($symbols as $symbol) {
            if (!in_array($symbol->kind, $kinds, true)) {
                continue;
            }
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
     * The class-like kinds this position admits, applied to the seam's results.
     * The per-candidate {@see ClassCandidateFilter::accepts()} predicate narrows
     * further (abstractness, throwability, …); this is the coarse kind gate.
     *
     * @return list<SymbolKind>
     */
    private function indexKinds(ClassCandidateFilter $filter): array
    {
        return match ($filter) {
            // Not a fourth literal: "every class-like" is the seam's own answer, so
            // a class-like kind added there must not be dropped by this gate.
            ClassCandidateFilter::Any => SymbolKind::forNameKind(NameKind::ClassLike),
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
