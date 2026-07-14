<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;
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
        $this->byNamespace ??= self::build();

        return $this->byNamespace[strtolower($namespace)] ?? new NamespaceContents();
    }

    /**
     * @return array<string, NamespaceContents>
     */
    private static function build(): array
    {
        /** @var array<string, list<string>> */
        $childNamespaces = [];
        /** @var array<string, list<CatalogSymbol>> */
        $symbols = [];

        foreach (self::internalSymbols() as $fqn => $kind) {
            $namespace = NamespacePath::namespaceOf($fqn);

            $symbols[strtolower($namespace)][] = new CatalogSymbol($fqn, $kind);

            // Register the namespace with each of its ancestors, so that
            // `Random\Engine\Xoshiro256StarStar` makes `Random` a child of the
            // global namespace and `Random\Engine` a child of `Random`.
            foreach (NamespacePath::ancestors($namespace) as $parent => $child) {
                $existing = $childNamespaces[strtolower($parent)] ?? [];
                if (!in_array($child, $existing, true)) {
                    $existing[] = $child;
                    $childNamespaces[strtolower($parent)] = $existing;
                }
            }
        }

        $contents = [];
        foreach (array_keys($childNamespaces + $symbols) as $namespace) {
            $contents[$namespace] = new NamespaceContents(
                $childNamespaces[$namespace] ?? [],
                $symbols[$namespace] ?? [],
            );
        }

        return $contents;
    }

    /**
     * Every built-in symbol, as fully qualified name => kind.
     *
     * @return array<string, NameKind>
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
                $symbols[$classLike] = NameKind::ClassLike;
            }
        }

        foreach (get_defined_functions()['internal'] as $function) {
            $symbols[$function] = NameKind::Function_;
        }

        $constants = get_defined_constants(categorize: true);
        unset($constants['user']);
        foreach ($constants as $category) {
            foreach (array_keys($category) as $constant) {
                $symbols[$constant] = NameKind::Constant;
            }
        }

        return $symbols;
    }

}
