<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespacePath;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Index\CatalogSymbol;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The highest-precedence {@see SymbolBackendInterface}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3). The
 * one authoritative store is a map of the {@see DeclaredSymbol}s each document
 * declares, built by {@see DeclarationSymbolInfoFactory}: `lookup`, `childrenOf`,
 * and `search` all derive from it, so there is no way for the surfaces to disagree
 * about what an open document declares (build-manifest step-46).
 */
final class OpenDocumentBackend implements SymbolBackendInterface, DocumentSymbolStoreInterface
{
    /** @var array<string, list<DeclaredSymbol>> URI -> the symbols it declares */
    private array $symbolsByUri = [];

    /** @var array<string, SymbolInfoInterface> Kind-qualified key -> metadata, derived for O(1) lookup */
    private array $byKey = [];

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $targetKey = NamespacePath::normalize($namespace->path);
        $childNamespaces = [];
        $symbols = [];

        foreach ($this->symbolsByUri as $uriSymbols) {
            foreach ($uriSymbols as $symbol) {
                $fqn = $symbol->name->fullyQualifiedName();
                $ns = NamespacePath::namespaceOf($fqn);
                if (NamespacePath::normalize($ns) === $targetKey) {
                    $symbols[] = new CatalogSymbol($fqn, $symbol->kind);
                    continue;
                }
                $below = NamespacePath::relativeTo($ns, $namespace->path);
                if ($below === null) {
                    continue;
                }
                $child = NamespacePath::join($namespace->path, NamespacePath::firstSegment($below));
                $childNamespaces[NamespacePath::normalize($child)] ??= $child;
            }
        }

        return new NamespaceContents(array_values($childNamespaces), $symbols);
    }

    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        return $this->byKey[$kind->keyFor($name)] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $results = [];
        foreach ($this->symbolsByUri as $uri => $symbols) {
            foreach ($symbols as $symbol) {
                if (!$symbol->kind->matches($kind)) {
                    continue;
                }
                if (!PrefixMatcher::matches($symbol->name->shortName, $prefix)) {
                    continue;
                }
                $results[] = new Symbol(
                    name: $symbol->name->shortName,
                    fullyQualifiedName: $symbol->name->fullyQualifiedName(),
                    kind: $symbol->info->symbolKind(),
                    location: new Location($uri, 0, 0, 0, 0),
                    nameKind: $symbol->kind,
                );
            }
        }
        return $results;
    }

    /**
     * Register the symbols declared in an open document, replacing any previously
     * registered for the same URI.
     */
    public function updateDocument(string $uri, DeclaredSymbol ...$symbols): void
    {
        $this->removeDocument($uri);

        $this->symbolsByUri[$uri] = array_values($symbols);
        foreach ($symbols as $symbol) {
            $this->byKey[$symbol->kind->keyFor($symbol->name)] = $symbol->info;
        }
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->symbolsByUri[$uri] ?? [] as $symbol) {
            unset($this->byKey[$symbol->kind->keyFor($symbol->name)]);
        }
        unset($this->symbolsByUri[$uri]);
    }
}
