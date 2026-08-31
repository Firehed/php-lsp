<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Closure;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PrefixMatcher;

/**
 * Shared filter-and-map for {@see PrefixSearchable} implementations: given a
 * list of catalog symbols already in memory, keep those whose short name
 * matches the prefix and wrap each in a {@see Symbol}.
 */
final class PrefixSearch
{
    /** @var array<string, SymbolKind> */
    private const array NAME_KIND_TO_SYMBOL_KIND = [
        'Function_' => SymbolKind::Function_,
        'Constant' => SymbolKind::Constant,
    ];

    /**
     * @param list<CatalogSymbol> $symbols
     * @param Closure(CatalogSymbol): Location $locationOf
     * @return list<Symbol>
     */
    public static function filter(
        array $symbols,
        string $prefix,
        NameKind $kind,
        Closure $locationOf,
    ): array {
        if (!array_key_exists($kind->name, self::NAME_KIND_TO_SYMBOL_KIND)) {
            return [];
        }
        $symbolKind = self::NAME_KIND_TO_SYMBOL_KIND[$kind->name];

        $results = [];
        foreach ($symbols as $catalogSymbol) {
            if (PrefixMatcher::matches($catalogSymbol->shortName(), $prefix)) {
                $results[] = new Symbol(
                    name: $catalogSymbol->shortName(),
                    fullyQualifiedName: $catalogSymbol->fullyQualifiedName,
                    kind: $symbolKind,
                    location: $locationOf($catalogSymbol),
                    nameKind: $kind,
                );
            }
        }
        return $results;
    }
}
