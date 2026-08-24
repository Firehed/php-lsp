<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Index\WorkspaceNamespaceSource;

/**
 * The highest-precedence {@see SymbolBackend}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3): the
 * backend reads the live symbol index and its own registered metadata directly.
 * Lookup is served from the {@see SymbolInfo} the write path registers per document;
 * enumeration and prefix search from the {@see SymbolIndex} it also populates. Both
 * stores come from one parse ({@see DocumentSymbolSink}, Plan 0002 §5.5 Step 3a(iv)).
 */
final class OpenDocumentBackend implements SymbolBackend
{
    /**
     * @var array<string, list<SymbolKind>>
     */
    private const array KINDS_BY_NAME_KIND = [
        'ClassLike' => [SymbolKind::Class_, SymbolKind::Enum_, SymbolKind::Interface_, SymbolKind::Trait_],
        'Function_' => [SymbolKind::Function_],
        'Constant' => [SymbolKind::Constant],
    ];

    /** @var array<string, SymbolInfo> Normalized kind-qualified key -> metadata */
    private array $byKey = [];

    /** @var array<string, list<string>> URI -> the keys it declared */
    private array $keysByUri = [];

    private readonly WorkspaceNamespaceSource $namespaces;

    public function __construct(
        private readonly SymbolIndex $index,
    ) {
        $this->namespaces = new WorkspaceNamespaceSource($index);
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
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
        return $this->index->findByPrefix($prefix, self::KINDS_BY_NAME_KIND[$kind->name]);
    }

    /**
     * Register the symbols declared in an open document for lookup, replacing any
     * previously registered for the same URI.
     *
     * Each symbol carries its own kind, so this backend never enumerates the kinds
     * and a new one reaches it without a signature change (Plan 0002 §5.6).
     */
    public function updateDocument(string $uri, DeclaredSymbol ...$symbols): void
    {
        $this->removeDocument($uri);

        $keys = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind->keyFor($symbol->name);
            $this->byKey[$key] = $symbol->info;
            $keys[] = $key;
        }
        $this->keysByUri[$uri] = $keys;
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->keysByUri[$uri] ?? [] as $key) {
            unset($this->byKey[$key]);
        }
        unset($this->keysByUri[$uri]);
    }
}
