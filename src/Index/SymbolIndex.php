<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use Firehed\PhpLsp\Domain\PrefixMatcher;

final class SymbolIndex
{
    /** @var array<string, Symbol> FQN -> Symbol */
    private array $byFqn = [];

    /** @var array<string, list<Symbol>> Name -> Symbols */
    private array $byName = [];

    /** @var array<string, list<string>> URI -> FQNs */
    private array $byUri = [];

    /** @var array<string, array<string, Symbol>> Lowercase namespace -> FQN -> Symbol */
    private array $byNamespace = [];

    public function add(Symbol $symbol): void
    {
        $this->byFqn[$symbol->fullyQualifiedName] = $symbol;

        $this->byName[$symbol->name] ??= [];
        $this->byName[$symbol->name][] = $symbol;

        $this->byUri[$symbol->location->uri] ??= [];
        $this->byUri[$symbol->location->uri][] = $symbol->fullyQualifiedName;

        $namespace = NamespacePath::normalize(NamespacePath::namespaceOf($symbol->fullyQualifiedName));
        $this->byNamespace[$namespace][$symbol->fullyQualifiedName] = $symbol;
    }

    /**
     * The symbols declared directly in a namespace, keyed on it at write time so
     * that discovery does not rescan the whole workspace on every keystroke.
     *
     * @return list<Symbol>
     */
    public function inNamespace(string $namespace): array
    {
        return array_values($this->byNamespace[NamespacePath::normalize($namespace)] ?? []);
    }

    /**
     * Every namespace that declares at least one symbol, in its real casing.
     * Intermediate namespaces that only contain deeper ones are not listed —
     * they are derivable from the descendant that witnesses them.
     *
     * @return list<string>
     */
    public function namespaces(): array
    {
        $namespaces = [];

        foreach ($this->byNamespace as $symbols) {
            foreach ($symbols as $symbol) {
                $namespaces[] = NamespacePath::namespaceOf($symbol->fullyQualifiedName);
                break;
            }
        }

        return $namespaces;
    }

    public function findByFqn(string $fqn): ?Symbol
    {
        return $this->byFqn[$fqn] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function findByName(string $name): array
    {
        return $this->byName[$name] ?? [];
    }

    /**
     * Find symbols matching a prefix, optionally filtered by name-resolution
     * category. Filtering compares the enum case names (strings), so this stays
     * off the kind-branch rule; the NameKind is authoritative on each Symbol.
     *
     * @return list<Symbol>
     */
    public function findByPrefix(string $prefix, ?NameKind $kind = null): array
    {
        $wantedName = $kind?->name;
        $results = [];

        foreach ($this->byFqn as $symbol) {
            if ($wantedName !== null && $symbol->nameKind?->name !== $wantedName) {
                continue;
            }
            if (PrefixMatcher::matches($symbol->name, $prefix)) {
                $results[] = $symbol;
            }
        }

        return $results;
    }

    public function clearByUri(string $uri): void
    {
        $fqns = $this->byUri[$uri] ?? [];

        foreach ($fqns as $fqn) {
            $symbol = $this->byFqn[$fqn] ?? null;
            if ($symbol !== null) {
                unset($this->byFqn[$fqn]);

                // Remove from byName
                $this->byName[$symbol->name] = array_values(array_filter(
                    $this->byName[$symbol->name] ?? [],
                    fn(Symbol $s) => $s->fullyQualifiedName !== $fqn,
                ));
                if ($this->byName[$symbol->name] === []) {
                    unset($this->byName[$symbol->name]);
                }

                $namespace = NamespacePath::normalize(NamespacePath::namespaceOf($fqn));
                unset($this->byNamespace[$namespace][$fqn]);
                if (($this->byNamespace[$namespace] ?? []) === []) {
                    unset($this->byNamespace[$namespace]);
                }
            }
        }

        unset($this->byUri[$uri]);
    }
}
