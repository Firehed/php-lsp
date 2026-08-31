<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilitiesProvider;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\DocblockParser;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolSource;
use Firehed\PhpLsp\Protocol\Range;
use Firehed\PhpLsp\Resolution\CodeResolver;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ReferenceResolver;

/** @phpstan-import-type CompletionItem from CompletionItemFactory */
final class SymbolCandidates
{
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
        $caps = $this->capabilities->getSessionCapabilities();
        $replaceRange = Range::forPrefix(
            $line,
            $character,
            $prefix,
            $caps->positionEncoding,
        );

        $snippets = $caps->snippetSupport;
        $seen = [];
        $items = [];
        foreach ($kinds as $kind) {
            $items = array_merge(
                $items,
                $this->fromSearch($prefix, $kind, $context, $classFilter, $replaceRange, $snippets, $seen),
                $this->fromImports($prefix, $kind, $context, $classFilter, $replaceRange, $snippets, $seen),
                $this->fromCurrentNamespace($prefix, $kind, $context, $classFilter, $replaceRange, $snippets, $seen),
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
        Range $replaceRange,
        bool $snippetSupport,
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
            $items[] = $this->buildItem($reference->text, $fqn, $kind, $replaceRange, $snippetSupport);
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
        Range $replaceRange,
        bool $snippetSupport,
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
            $items[] = $this->buildItem($shortName, $fqn, $kind, $replaceRange, $snippetSupport);
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
        Range $replaceRange,
        bool $snippetSupport,
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
            $items[] = $this->buildItem($reference->text, $fqn, $kind, $replaceRange, $snippetSupport);
        }

        return $items;
    }

    /**
     * @return CompletionItem
     */
    private function buildItem(
        string $reference,
        string $fqn,
        NameKind $kind,
        Range $replaceRange,
        bool $snippetSupport,
        ?string $filterText = null,
    ): array {
        [$detail, $documentation] = $kind->isFunction()
            ? $this->functionDetail($fqn)
            : [null, null];

        return CompletionItemFactory::forSymbol(
            $reference,
            $fqn,
            $kind,
            $replaceRange,
            $snippetSupport,
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
        $doc = null;
        if ($info->docblock !== null && $info->docblock !== '') {
            $extracted = DocblockParser::extractDescription($info->docblock);
            if ($extracted !== '') {
                $doc = $extracted;
            }
        }
        return [$info->format(), $doc];
    }

    private function acceptsClassLike(string $fqn, ClassCandidateFilter $filter): bool
    {
        /** @var class-string $fqn */
        $className = TypeFactory::className($fqn);
        return $filter->accepts($className, $this->codeResolver);
    }
}
