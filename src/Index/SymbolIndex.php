<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

final class SymbolIndex
{
    /** @var array<string, Symbol> FQN -> Symbol */
    private array $byFqn = [];

    /** @var array<string, list<Symbol>> Name -> Symbols */
    private array $byName = [];

    /** @var array<string, list<string>> URI -> FQNs */
    private array $byUri = [];

    public function add(Symbol $symbol): void
    {
        $this->byFqn[$symbol->fullyQualifiedName] = $symbol;

        $this->byName[$symbol->name] ??= [];
        $this->byName[$symbol->name][] = $symbol;

        $this->byUri[$symbol->location->uri] ??= [];
        $this->byUri[$symbol->location->uri][] = $symbol->fullyQualifiedName;
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
                if (empty($this->byName[$symbol->name])) {
                    unset($this->byName[$symbol->name]);
                }
            }
        }

        unset($this->byUri[$uri]);
    }
}
