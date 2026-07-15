<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Resolution\NameKind;
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
final class ReflectionNamespaceSource implements NamespaceCatalog
{
    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents */
    private ?array $byNamespace = null;

    public function childrenOf(string $namespace): NamespaceContents
    {
        $this->byNamespace ??= NamespaceContents::indexByNamespace(self::internalSymbols());

        return $this->byNamespace[strtolower($namespace)] ?? new NamespaceContents();
    }

    /**
     * @return list<CatalogSymbol>
     */
    private static function internalSymbols(): array
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

        $constants = get_defined_constants(categorize: true);
        unset($constants['user']);
        foreach ($constants as $category) {
            foreach (array_keys($category) as $constant) {
                $symbols[] = new CatalogSymbol($constant, NameKind::Constant);
            }
        }

        return $symbols;
    }
}
