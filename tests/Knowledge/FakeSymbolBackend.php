<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * An in-memory {@see SymbolBackend} configured with fixed answers, so
 * {@see \Firehed\PhpLsp\Tests\Knowledge\CompositeSymbolSourceTest} can prove the
 * composite's precedence and merge behavior without standing up real sources.
 *
 * Kind-agnostic like the real backends: a symbol carries its own kind, so a kind this
 * file has never heard of is configurable without a new parameter (Plan 0002 §5.6).
 */
final class FakeSymbolBackend implements SymbolBackend
{
    /** @var array<string, SymbolInfo> Kind-qualified key -> info */
    private array $byKey = [];

    /**
     * @param list<DeclaredSymbol> $symbols Keyed here by each one's own case rule
     * @param array<string, NamespaceContents> $namespaces Path -> contents
     * @param list<Symbol> $searchResults Returned (prefix-filtered on short name)
     */
    public function __construct(
        array $symbols = [],
        private readonly array $namespaces = [],
        private readonly array $searchResults = [],
    ) {
        foreach ($symbols as $symbol) {
            $this->byKey[$symbol->kind->keyFor($symbol->name)] = $symbol->info;
        }
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces[$namespace->path] ?? new NamespaceContents();
    }

    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return $this->byKey[$kind->keyFor($name)] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return $this->searchClassLikes($prefix);
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return array_values(array_filter(
            $this->searchResults,
            static fn(Symbol $symbol): bool => str_starts_with(
                strtolower($symbol->name),
                strtolower($prefix),
            ),
        ));
    }
}
