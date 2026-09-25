<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;

/**
 * The store the {@see SymbolSourceInterface} backends whose answers come from an
 * in-memory set of {@see DeclaredSymbol}s share: `lookup`, `childrenOf` and
 * `search` all read one map keyed by source. The lookup index is rebuilt after
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

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $targetKey = $namespace->normalize();
        $childNamespaces = [];
        $symbols = [];
        $seenSymbolKey = [];

        foreach ($this->symbolsBySource as $sourceSymbols) {
            foreach ($sourceSymbols as $symbol) {
                $fqn = $symbol->name->fullyQualifiedName();
                $ns = new NamespaceName(NamespaceName::namespaceOf($fqn));
                if ($ns->normalize() === $targetKey) {
                    $key = $symbol->kind->keyFor($symbol->name);
                    if (array_key_exists($key, $seenSymbolKey)) {
                        continue;
                    }
                    $seenSymbolKey[$key] = true;
                    $symbols[] = new CatalogSymbol($fqn, $symbol->kind);
                    continue;
                }
                $below = $ns->relativeTo($namespace);
                if ($below === null) {
                    continue;
                }
                $child = NamespaceName::join($namespace->path, NamespaceName::firstSegment($below));
                $childNamespaces[(new NamespaceName($child))->normalize()] ??= $child;
            }
        }

        return new NamespaceContents(array_values($childNamespaces), $symbols);
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $results = [];
        foreach ($this->symbolsBySource as $source => $symbols) {
            foreach ($symbols as $symbol) {
                if (!$symbol->kind->matches($kind)) {
                    continue;
                }
                if (!PrefixMatcher::matches($symbol->name->shortName, $prefix)) {
                    continue;
                }
                $key = $symbol->kind->keyFor($symbol->name);
                $results[$key] ??= new Symbol(
                    name: $symbol->name->shortName,
                    fullyQualifiedName: $symbol->name->fullyQualifiedName(),
                    kind: $symbol->info->symbolKind(),
                    location: new Location($source, 0, 0, 0, 0),
                    nameKind: $symbol->kind,
                );
            }
        }
        return array_values($results);
    }

    /**
     * Replace the symbols recorded under $source with $symbols. Rebuilds the
     * lookup index from the resulting map, so lookup, `childrenOf`, and
     * `search` all resolve the same declaration for any shared name.
     */
    private function setSymbolsFor(string $source, DeclaredSymbol ...$symbols): void
    {
        $this->symbolsBySource[$source] = array_values($symbols);
        $this->rebuildLookupIndex();
    }

    /**
     * Drop every symbol recorded under $source. A source that was never
     * written is a no-op.
     */
    private function removeSymbolsFor(string $source): void
    {
        unset($this->symbolsBySource[$source]);
        $this->rebuildLookupIndex();
    }

    private function rebuildLookupIndex(): void
    {
        $this->byKey = [];
        foreach ($this->symbolsBySource as $sourceSymbols) {
            foreach ($sourceSymbols as $symbol) {
                $this->byKey[$symbol->kind->keyFor($symbol->name)] ??= $symbol->info;
            }
        }
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        return $this->byKey[$kind->keyFor($name)] ?? null;
    }
}
