<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Index\WorkspaceNamespaceSource;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * The highest-precedence {@see SymbolBackend}: the documents the editor has open
 * (RFC 1 §5.3). Its answers override every on-disk backend, so a user's unsaved
 * edits are honored — including edits to a vendored file opened in the editor.
 *
 * Open documents change on every keystroke and are never cached (RFC 1 §5.3): the
 * backend reads the live symbol index and its own registered class metadata
 * directly. Class-like lookup is served from the {@see ClassInfo} registered per
 * document by the write path; namespace enumeration and prefix search are served
 * from the {@see SymbolIndex} the write path also populates. The write path feeds
 * both stores from one parse ({@see DocumentSymbolSink}, Plan 0002 §5.5 Step 3a(iv));
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

    /** @var array<string, FunctionInfo> Lowercase FQN -> function metadata */
    private array $functionsByFqn = [];

    /** @var array<string, list<string>> URI -> the lowercase function FQNs it declared */
    private array $functionFqnsByUri = [];

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
        return $this->byFqn[self::key(NameKind::ClassLike, $name->fqn)] ?? null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->functionsByFqn[$name->kind()->normalize($name->qualifiedName)] ?? null;
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
            $key = self::key(NameKind::ClassLike, $classInfo->name->fqn);
            $this->byFqn[$key] = $classInfo;
            $keys[] = $key;
        }
        $this->fqnsByUri[$uri] = $keys;

        $functionKeys = [];
        foreach ($functions as $fqn => $functionInfo) {
            $key = self::key(NameKind::Function_, $fqn);
            $this->functionsByFqn[$key] = $functionInfo;
            $functionKeys[] = $key;
        }
        $this->functionFqnsByUri[$uri] = $functionKeys;
    }

    public function removeDocument(string $uri): void
    {
        foreach ($this->fqnsByUri[$uri] ?? [] as $key) {
            unset($this->byFqn[$key]);
        }
        unset($this->fqnsByUri[$uri]);

        foreach ($this->functionFqnsByUri[$uri] ?? [] as $key) {
            unset($this->functionsByFqn[$key]);
        }
        unset($this->functionFqnsByUri[$uri]);
    }

    /**
     * Registration and lookup must agree on the case rule, and that rule differs by
     * kind, so both go through {@see NameKind::normalize()} rather than a local
     * lowercasing of the whole FQN — which is right for these two kinds and wrong
     * for a constant.
     */
    private static function key(NameKind $kind, string $fqn): string
    {
        return $kind->normalize(QualifiedName::fromFullyQualified($fqn));
    }
}
