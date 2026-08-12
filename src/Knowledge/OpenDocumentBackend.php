<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
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
 * Lookup is served from the {@see SymbolInfo} registered per document by the write
 * path; namespace enumeration and prefix search are served from the
 * {@see SymbolIndex} the write path also populates. The write path feeds both stores
 * from one parse ({@see DocumentSymbolSink}, Plan 0002 §5.5 Step 3a(iv)); here they
 * are read as they stand.
 */
final class OpenDocumentBackend implements SymbolBackend
{
    /**
     * The symbol kinds that are class-likes. Prefix search covers this namespace
     * alone; the function and constant namespaces are Step 3b (Plan 0002 §5.3).
     *
     * @var list<SymbolKind>
     */
    private const array CLASS_LIKE_KINDS = [
        SymbolKind::Class_,
        SymbolKind::Enum_,
        SymbolKind::Interface_,
        SymbolKind::Trait_,
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
        return $this->byKey[self::key($kind, $name)] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return $this->index->findByPrefix($prefix, self::CLASS_LIKE_KINDS);
    }

    /**
     * Register the class-likes and functions declared in an open document for
     * lookup, replacing any previously registered for the same URI.
     *
     * Functions arrive keyed because {@see FunctionInfo} carries only the short
     * name; the caller read the qualified one from the declaration.
     *
     * @param list<ClassInfo> $classes
     * @param array<string, FunctionInfo> $functions Fully-qualified name -> metadata
     */
    public function updateDocument(string $uri, array $classes, array $functions = []): void
    {
        $this->removeDocument($uri);

        $keys = [];
        foreach ($classes as $classInfo) {
            $keys[] = $this->register(NameKind::ClassLike, $classInfo->name->fqn, $classInfo);
        }
        foreach ($functions as $fqn => $functionInfo) {
            $keys[] = $this->register(NameKind::Function_, $fqn, $functionInfo);
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

    private function register(NameKind $kind, string $fqn, SymbolInfo $info): string
    {
        $key = self::key($kind, QualifiedName::fromFullyQualified($fqn));
        $this->byKey[$key] = $info;

        return $key;
    }

    /**
     * Registration and lookup must agree on the case rule, and that rule differs by
     * kind, so both go through {@see NameKind::normalize()} rather than a local
     * lowercasing of the whole FQN — which is right for class-likes and functions
     * and wrong for a constant.
     *
     * The kind is part of the key because one store now holds every kind, and PHP's
     * symbol namespaces are independent: a class and a function may share a
     * spelling without being the same symbol.
     */
    private static function key(NameKind $kind, QualifiedName $name): string
    {
        return $kind->name . '|' . $kind->normalize($name);
    }
}
