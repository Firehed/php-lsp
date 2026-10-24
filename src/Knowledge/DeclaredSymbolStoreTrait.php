<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;

/**
 * The store the {@see SymbolSourceInterface} backends whose answers come from an
 * in-memory set of {@see DeclaredSymbol}s share: `lookup`, `childrenOf` and
 * `search` all read one map keyed by source. The indexes are rebuilt after
 * every write and remove, so the first declaration of a name in map order wins
 * every answer; the three surfaces can never disagree about what the store
 * declares.
 */
trait DeclaredSymbolStoreTrait
{
    /** @var array<string, list<DeclaredSymbol>> Source key -> the symbols it declares */
    private array $symbolsBySource = [];

    /** @var array<string, SymbolInfoInterface> Kind-qualified key -> metadata, first source in map order wins */
    private array $byKey = [];

    /** @var array<string, NamespaceContents> Lowercase namespace -> contents, first source in map order wins */
    private array $byNamespace = [];

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->byNamespace[$namespace->normalize()] ?? new NamespaceContents();
    }

    /**
     * @return list<QualifiedName>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $results = [];
        foreach ($this->symbolsBySource as $symbols) {
            foreach ($symbols as $symbol) {
                if (!$symbol->kind->matches($kind)) {
                    continue;
                }
                if (!PrefixMatcher::matches($symbol->name->shortName, $prefix)) {
                    continue;
                }
                $results[$symbol->kind->keyFor($symbol->name)] ??= $symbol->name;
            }
        }
        return array_values($results);
    }

    /**
     * Replace the symbols recorded under $source with $symbols. Rebuilds the
     * indexes from the resulting map, so lookup, `childrenOf`, and
     * `search` all resolve the same declaration for any shared name.
     */
    private function setSymbolsFor(string $source, DeclaredSymbol ...$symbols): void
    {
        $this->symbolsBySource[$source] = array_values($symbols);
        $this->rebuildIndexes();
    }

    /**
     * Drop every symbol recorded under $source. A source that was never
     * written is a no-op.
     */
    private function removeSymbolsFor(string $source): void
    {
        unset($this->symbolsBySource[$source]);
        $this->rebuildIndexes();
    }

    /**
     * Replace every recorded symbol, rebuilding the indexes once rather than
     * once per source.
     *
     * @param array<string, list<DeclaredSymbol>> $symbolsBySource Source key -> the symbols it declares
     */
    private function replaceAllSymbols(array $symbolsBySource): void
    {
        $this->symbolsBySource = $symbolsBySource;
        $this->rebuildIndexes();
    }

    private function rebuildIndexes(): void
    {
        $this->byKey = [];
        $catalog = [];
        foreach ($this->symbolsBySource as $sourceSymbols) {
            foreach ($sourceSymbols as $symbol) {
                $this->byKey[$symbol->kind->keyFor($symbol->name)] ??= $symbol->info;
                $catalog[] = new CatalogSymbol($symbol->name->fullyQualifiedName(), $symbol->kind);
            }
        }
        $this->byNamespace = NamespaceContents::indexByNamespace($catalog);
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        return $this->byKey[$kind->keyFor($name)] ?? null;
    }
}
