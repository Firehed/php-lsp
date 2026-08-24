<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;

/**
 * The lowest-precedence {@see SymbolBackend}: the symbols built into PHP and its
 * loaded extensions, described through reflection. It is consulted only after the
 * open-document, workspace, and vendor backends, so a name any of them can resolve
 * never reaches reflection (RFC 1 §5.3).
 *
 * Built-ins are fixed for a given target environment, so a resolved symbol is cached
 * (RFC 1 §5.3). This backend is reflection-backed and therefore describes the
 * *server's* runtime, not the project's target — a known §4.7 gap deferred to Step 5
 * (Plan 0002 §5); the interim treats every reflected built-in as available.
 *
 * Prefix search is empty here for the same reason as on disk: a bare prefix would
 * mean surfacing built-ins that do not resolve unqualified in the file's namespace,
 * which is auto-import, a separate concern — not this backend's job.
 */
final class BuiltinBackend implements SymbolBackend
{
    public function __construct(
        private readonly ReflectionSymbolInfoFactory $infoFactory,
        private readonly NamespaceCatalog $namespaces,
        private readonly SymbolCache $cache,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return $this->cache->remember(
            $name,
            $kind,
            fn(): ?SymbolInfo => $this->infoFactory->fromReflection($name, $kind),
        );
    }

    /**
     * @return list<never>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return [];
    }

    /**
     * @return list<never>
     */
    public function searchClassLikes(string $prefix): array
    {
        return [];
    }
}
