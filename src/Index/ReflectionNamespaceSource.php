<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use ReflectionClass;

/**
 * Discovers the symbols built into PHP itself, via reflection.
 *
 * Two things make this less obvious than it looks:
 *
 * - `get_declared_classes()` reports every class loaded in *this* process,
 *   which includes the language server and its own vendored dependencies. Only
 *   the internal ones are built-ins.
 * - Built-ins are not all global. `Random\Randomizer` and classes contributed by
 *   extensions live in namespaces, so each symbol is filed under the namespace
 *   its reflected name actually carries.
 *
 * The index is built once, on first use: the set of internal symbols is fixed
 * for the life of the process.
 */
final class ReflectionNamespaceSource implements NamespaceCatalog, PrefixSearchable
{
    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents */
    private ?array $byNamespace = null;

    /** @var array<string, list<CatalogSymbol>>|null Kind name -> symbols */
    private ?array $symbolsByKind = null;

    public function __construct(
        private readonly InternalConstantSet $constants = new InternalConstantSet(),
    ) {
    }

    /**
     * @return list<Symbol>
     */
    public function searchByPrefix(string $prefix, NameKind $kind): array
    {
        return PrefixSearch::filter(
            $this->symbolsOfKind($kind),
            $prefix,
            $kind,
            static fn(): Location => new Location('', 0, 0, 0, 0),
        );
    }

    public function childrenOf(string $namespace): NamespaceContents
    {
        $this->byNamespace ??= NamespaceContents::indexByNamespace($this->internalSymbols());

        return $this->byNamespace[NamespacePath::normalize($namespace)] ?? new NamespaceContents();
    }

    /**
     * @return list<CatalogSymbol>
     */
    private function symbolsOfKind(NameKind $kind): array
    {
        if ($this->symbolsByKind === null) {
            $this->symbolsByKind = [];
            foreach (NameKind::cases() as $k) {
                $this->symbolsByKind[$k->name] = [];
            }
            foreach ($this->internalSymbols() as $symbol) {
                $this->symbolsByKind[$symbol->kind->name][] = $symbol;
            }
        }
        return $this->symbolsByKind[$kind->name];
    }

    /**
     * @return list<CatalogSymbol>
     */
    private function internalSymbols(): array
    {
        $symbols = [];

        $classLikes = [
            ...get_declared_classes(),
            ...get_declared_interfaces(),
            ...get_declared_traits(),
        ];
        foreach ($classLikes as $classLike) {
            if ((new ReflectionClass($classLike))->isInternal()) {
                $symbols[] = new CatalogSymbol($classLike, NameKind::ClassLike);
            }
        }

        foreach (get_defined_functions()['internal'] as $function) {
            $symbols[] = new CatalogSymbol($function, NameKind::Function_);
        }

        foreach (array_keys($this->constants->all()) as $constant) {
            $symbols[] = new CatalogSymbol($constant, NameKind::Constant);
        }

        return $symbols;
    }
}
