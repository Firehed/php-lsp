<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
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
 * backend reads the live symbol index and its own registered class metadata
 * directly. Class-like lookup is served from the {@see ClassInfo} registered per
 * document by the write path; namespace enumeration and prefix search are served
 * from the {@see SymbolIndex} the write path also populates. That both stores are
 * fed from one document is the double write Step 3a(iv) collapses (Plan 0002 §5.5);
 * here they are read as they stand.
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

    /** @var array<string, ClassInfo> Lowercase FQN -> class metadata */
    private array $byFqn = [];

    /** @var array<string, list<string>> URI -> the lowercase FQNs it declared */
    private array $fqnsByUri = [];

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

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        return $this->byFqn[self::normalizeKey($name->fqn)] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return $this->index->findByPrefix($prefix, self::CLASS_LIKE_KINDS);
    }

    /**
     * Register the class-likes declared in an open document for lookup, replacing
     * any previously registered for the same URI.
     *
     * @param list<ClassInfo> $classes
     */
    public function updateDocument(string $uri, array $classes): void
    {
        $this->removeDocument($uri);

        $keys = [];
        foreach ($classes as $classInfo) {
            $key = self::normalizeKey($classInfo->name->fqn);
            $this->byFqn[$key] = $classInfo;
            $keys[] = $key;
        }
        $this->fqnsByUri[$uri] = $keys;
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->fqnsByUri[$uri] ?? [] as $key) {
            unset($this->byFqn[$key]);
        }
        unset($this->fqnsByUri[$uri]);
    }

    private static function normalizeKey(string $fqn): string
    {
        return strtolower(ltrim($fqn, '\\'));
    }
}
