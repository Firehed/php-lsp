<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ReferenceResolver;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;

/**
 * The one source of symbol completions. For an unqualified prefix it does flat
 * lookup — search, imports, and children of the current namespace — with each
 * candidate written in its shortest form via {@see ReferenceResolver}. For a
 * namespace-qualified or `\`-rooted prefix it navigates the tree instead,
 * offering child namespaces as Module nodes and leaf symbols of the requested
 * kinds. Nav-node offering is structural (a namespace is a namespace whatever
 * lives inside), but leaf symbols honour $kinds — so a class-only position no
 * longer picks up a function or constant through navigation (#317, #383).
 *
 * @phpstan-import-type CompletionItem from CompletionItemFactory
 */
final class SymbolCandidates
{
    // A child namespace with this many members or fewer is inlined rather than
    // offered as a node. A starting point, expected to be tuned with real use.
    private const INLINE_THRESHOLD = 5;

    public function __construct(
        private readonly SymbolSource $symbolSource,
        private readonly CodeResolver $codeResolver,
        private readonly SessionCapabilitiesProvider $capabilities,
    ) {
    }

    /**
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    public function find(
        string $prefix,
        TextDocument $document,
        int $line,
        int $character,
        array $kinds,
        ClassCandidateFilter $classFilter,
    ): array {
        $context = $this->codeResolver->getNameContext($document, $line);
        $range = $this->replaceRange($line, $character, $prefix);
        $snippets = $this->capabilities->getSessionCapabilities()->snippetSupport;

        if (str_starts_with($prefix, '\\')) {
            return $this->navigateAbsolute(substr($prefix, 1), $kinds, $classFilter, $range, $snippets);
        }
        if (str_contains($prefix, '\\')) {
            return $this->navigateQualified($prefix, $context, $kinds, $classFilter, $range, $snippets);
        }

        $seen = [];
        $items = [];
        foreach ($kinds as $kind) {
            $items = array_merge(
                $items,
                $this->fromSearch($prefix, $kind, $context, $classFilter, $range, $snippets, $seen),
                $this->fromImports($prefix, $kind, $context, $classFilter, $range, $snippets, $seen),
                $this->fromCurrentNamespace($prefix, $kind, $context, $classFilter, $range, $snippets, $seen),
            );
        }
        $items = array_merge($items, $this->descendBare($prefix, $context, $kinds, $classFilter, $range, $snippets));

        return $items;
    }

    /**
     * Navigate a `use` import: always absolute (a `use` name resolves from the
     * global namespace regardless of the file's own namespace or imports), and
     * always class-like. The classifier deliberately does not match `use function`
     * or `use const` — those are separate scopes (#239, #317).
     *
     * @return list<CompletionItem>
     */
    public function forUseStatement(
        string $prefix,
        int $line,
        int $character,
        ClassCandidateFilter $classFilter,
    ): array {
        $range = $this->replaceRange($line, $character, $prefix);
        $snippets = $this->capabilities->getSessionCapabilities()->snippetSupport;

        return $this->navigateAbsolute(
            ltrim($prefix, '\\'),
            [NameKind::ClassLike],
            $classFilter,
            $range,
            $snippets,
        );
    }

    /**
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    private function navigateAbsolute(
        string $qualified,
        array $kinds,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): array {
        return $this->offerContentsOf(
            NamespacePath::namespaceOf($qualified),
            NamespacePath::shortNameOf($qualified),
            $kinds,
            $classFilter,
            $range,
            $snippets,
        );
    }

    /**
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    private function navigateQualified(
        string $prefix,
        NameContext $context,
        array $kinds,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): array {
        $alias = NamespacePath::firstSegment($prefix);
        $base = array_key_exists($alias, $context->classImports)
            ? $context->classImports[$alias]
            : NamespacePath::join($context->namespace, $alias);
        $rest = substr($prefix, strlen($alias) + 1);

        return $this->offerContentsOf(
            NamespacePath::join($base, NamespacePath::namespaceOf($rest)),
            NamespacePath::shortNameOf($rest),
            $kinds,
            $classFilter,
            $range,
            $snippets,
        );
    }

    /**
     * The child namespaces of $namespace, plus the leaf symbols declared in it of
     * an allowed kind, each filtered by the segment prefix $short.
     *
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    private function offerContentsOf(
        string $namespace,
        string $short,
        array $kinds,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): array {
        $contents = $this->symbolSource->childrenOf(new NamespaceName($namespace));

        $items = [];
        foreach ($contents->childNamespaces as $child) {
            $segment = NamespacePath::shortNameOf($child);
            if (!PrefixMatcher::matches($segment, $short)) {
                continue;
            }
            $items = array_merge(
                $items,
                $this->offerChildNamespace($child, $segment, $kinds, $classFilter, $range, $snippets),
            );
        }
        foreach ($contents->symbols as $symbol) {
            if (!$this->kindAllowed($symbol->kind, $kinds)) {
                continue;
            }
            if (!PrefixMatcher::matches($symbol->shortName(), $short)) {
                continue;
            }
            $item = $this->offerLeaf($symbol, $symbol->shortName(), null, $classFilter, $range, $snippets);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Bare-name descent: an import or a child of the current namespace whose
     * name matches $prefix and is itself a navigable namespace is offered
     * through the same node/inline path as absolute navigation — so a small
     * imported namespace's members appear directly and a large one is a node.
     *
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    private function descendBare(
        string $prefix,
        NameContext $context,
        array $kinds,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): array {
        $targets = [];
        foreach (
            $this->symbolSource->childrenOf(new NamespaceName($context->namespace))->childNamespaces as $child
        ) {
            $targets[NamespacePath::shortNameOf($child)] = $child;
        }
        foreach ($context->classImports as $alias => $fqcn) {
            $targets[$alias] = $fqcn;
        }

        $items = [];
        foreach ($targets as $reference => $fqcn) {
            if (!PrefixMatcher::matches($reference, $prefix) || !$this->isNavigable($fqcn)) {
                continue;
            }
            $items = array_merge(
                $items,
                $this->offerChildNamespace($fqcn, $reference, $kinds, $classFilter, $range, $snippets),
            );
        }

        return $items;
    }

    /**
     * @param array<string, true> $seen
     * @return list<CompletionItem>
     */
    private function fromSearch(
        string $prefix,
        NameKind $kind,
        NameContext $context,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
        array &$seen,
    ): array {
        $items = [];
        foreach ($this->symbolSource->search($prefix, $kind) as $symbol) {
            $fqn = $symbol->fullyQualifiedName;
            if (array_key_exists($fqn, $seen)) {
                continue;
            }
            if ($kind->isClassLike() && !$this->acceptsClassLike($fqn, $classFilter)) {
                continue;
            }
            $reference = ReferenceResolver::resolve($fqn, $kind, $context);
            if (!$reference->isReachable()) {
                continue;
            }
            $seen[$fqn] = true;
            $items[] = $this->buildLeaf($reference->text, $fqn, $kind, $range, $snippets);
        }

        return $items;
    }

    /**
     * @param array<string, true> $seen
     * @return list<CompletionItem>
     */
    private function fromImports(
        string $prefix,
        NameKind $kind,
        NameContext $context,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
        array &$seen,
    ): array {
        $items = [];
        foreach ($context->importsFor($kind) as $shortName => $fqn) {
            if (array_key_exists($fqn, $seen)) {
                continue;
            }
            if (!PrefixMatcher::matches($shortName, $prefix)) {
                continue;
            }
            if ($kind->isClassLike() && !$this->acceptsClassLike($fqn, $classFilter)) {
                continue;
            }
            $seen[$fqn] = true;
            $items[] = $this->buildLeaf($shortName, $fqn, $kind, $range, $snippets);
        }

        return $items;
    }

    /**
     * @param array<string, true> $seen
     * @return list<CompletionItem>
     */
    private function fromCurrentNamespace(
        string $prefix,
        NameKind $kind,
        NameContext $context,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
        array &$seen,
    ): array {
        if ($context->namespace === '') {
            return [];
        }

        $contents = $this->symbolSource->childrenOf(new NamespaceName($context->namespace));
        $items = [];
        foreach ($contents->symbols as $symbol) {
            if (!$kind->matches($symbol->kind)) {
                continue;
            }
            if (!PrefixMatcher::matches($symbol->shortName(), $prefix)) {
                continue;
            }
            $fqn = $symbol->fullyQualifiedName;
            if (array_key_exists($fqn, $seen)) {
                continue;
            }
            if ($kind->isClassLike() && !$this->acceptsClassLike($fqn, $classFilter)) {
                continue;
            }
            $reference = ReferenceResolver::resolve($fqn, $kind, $context);
            if (!$reference->isReachable()) {
                continue;
            }
            $seen[$fqn] = true;
            $items[] = $this->buildLeaf($reference->text, $fqn, $kind, $range, $snippets);
        }

        return $items;
    }

    /**
     * A child namespace with few members is inlined — its contents offered
     * qualified by the segment — so the user need not step through it. A larger
     * one is a node to navigate into. A same-named class is a separate symbol of
     * the parent, so it is always offered alongside either form. Inlining is one
     * level: an inlined namespace's own child namespaces become qualified nodes.
     *
     * @param list<NameKind> $kinds
     * @return list<CompletionItem>
     */
    private function offerChildNamespace(
        string $child,
        string $segment,
        array $kinds,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): array {
        $contents = $this->symbolSource->childrenOf(new NamespaceName($child));
        $elementCount = count($contents->childNamespaces) + count($contents->symbols);
        if ($elementCount === 0 || $elementCount > self::INLINE_THRESHOLD) {
            $item = CompletionItemFactory::forNamespace($segment, $child, $range);
            $item['sortText'] = '1_' . $item['label'];
            return [$item];
        }

        $items = [];
        foreach ($contents->childNamespaces as $grandchild) {
            $reference = $segment . '\\' . NamespacePath::shortNameOf($grandchild);
            $item = CompletionItemFactory::forNamespace($reference, $grandchild, $range);
            $item['sortText'] = '1_' . $item['label'];
            $items[] = $item;
        }
        foreach ($contents->symbols as $symbol) {
            if (!$this->kindAllowed($symbol->kind, $kinds)) {
                continue;
            }
            $reference = $segment . '\\' . $symbol->shortName();
            $item = $this->offerLeaf($symbol, $reference, $reference, $classFilter, $range, $snippets);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return ?CompletionItem
     */
    private function offerLeaf(
        CatalogSymbol $symbol,
        string $reference,
        ?string $filterText,
        ClassCandidateFilter $classFilter,
        Range $range,
        bool $snippets,
    ): ?array {
        $fqn = $symbol->fullyQualifiedName;
        if ($symbol->kind->isClassLike()) {
            /** @var class-string $fqn */
            $className = TypeFactory::className($fqn);
            if (!$this->codeResolver->isClassLike($className)) {
                return null;
            }
            if (!$classFilter->accepts($className, $this->codeResolver)) {
                return null;
            }
        }

        $item = $this->buildLeaf($reference, $fqn, $symbol->kind, $range, $snippets, $filterText);
        $item['sortText'] = '0_' . $item['label'];
        return $item;
    }

    /**
     * @return CompletionItem
     */
    private function buildLeaf(
        string $reference,
        string $fqn,
        NameKind $kind,
        Range $range,
        bool $snippets,
        ?string $filterText = null,
    ): array {
        [$detail, $documentation] = $kind->isFunction()
            ? $this->functionDetail($fqn)
            : [null, null];

        return CompletionItemFactory::forSymbol(
            $reference,
            $fqn,
            $kind,
            $range,
            $snippets,
            $filterText,
            $detail,
            $documentation,
        );
    }

    /**
     * @return array{?string, ?string} [detail, documentation]
     */
    private function functionDetail(string $fqn): array
    {
        $info = $this->symbolSource->lookupFunction(FunctionName::fromFullyQualified($fqn));
        if ($info === null) {
            return [null, null];
        }
        $presented = ResolvedSymbolPresenter::present($info);
        return [$presented->signature, $presented->documentation];
    }

    private function acceptsClassLike(string $fqn, ClassCandidateFilter $filter): bool
    {
        /** @var class-string $fqn */
        $className = TypeFactory::className($fqn);
        return $filter->accepts($className, $this->codeResolver);
    }

    /**
     * @param list<NameKind> $kinds
     */
    private function kindAllowed(NameKind $kind, array $kinds): bool
    {
        foreach ($kinds as $allowed) {
            if ($allowed->matches($kind)) {
                return true;
            }
        }
        return false;
    }

    private function isNavigable(string $namespace): bool
    {
        $contents = $this->symbolSource->childrenOf(new NamespaceName($namespace));

        return count($contents->childNamespaces) > 0 || count($contents->symbols) > 0;
    }

    private function replaceRange(int $line, int $character, string $prefix): Range
    {
        return Range::forPrefix(
            $line,
            $character,
            $prefix,
            $this->capabilities->getSessionCapabilities()->positionEncoding,
        );
    }
}
