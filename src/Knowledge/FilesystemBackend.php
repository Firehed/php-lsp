<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Parser\ParserService;

/**
 * A {@see SymbolBackend} over PHP files on disk, resolved through Composer's
 * autoload maps: the workspace's own code, and vendored dependencies. The same
 * class serves both roles — the difference is only which autoload map subset it is
 * given (Plan 0002 §3a: the workspace/vendor precedence split), so one lookup
 * mechanism covers both rather than two hand-written copies.
 *
 * Lookup locates the file for a name and parses that one file — no
 * `vendor/` pre-index (RFC 1 §3, lazy-first). Results are held behind the
 * replaceable cache seam (RFC 1 §5.3): a file on disk is stable while unchanged, so
 * a resolved symbol is memoized. An on-disk change to a file is signalled through
 * {@see invalidate()} ({@see Invalidatable}), which evicts that file's cached
 * symbols and drops cached namespace listings so the next query reflects disk
 * (RFC 1 §5.2, §5.3).
 *
 * Namespace enumeration is a directory listing through the same autoload map
 * ({@see NamespaceCatalog}). Prefix search is empty: a name→file map exists for
 * classes, but a bare prefix has no such map, so project-wide search over disk is
 * the deferred workspace-index scope (RFC 1 §3), not an unbounded walk here.
 */
final class FilesystemBackend implements SymbolBackend, Invalidatable
{
    /**
     * The symbols derived from each file, recorded so invalidation can evict them.
     *
     * @var array<string, list<array{QualifiedName, NameKind}>>
     */
    private array $symbolsByPath = [];

    public function __construct(
        private readonly SymbolLocator $locator,
        private readonly NamespaceCatalog $namespaces,
        private readonly ParserService $parser,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
        private readonly SymbolCache $cache,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    public function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        return $this->cache->remember($name, $kind, function () use ($name, $kind): ?SymbolInfo {
            $filePath = $this->locator->locate($name, $kind);
            if ($filePath === null) {
                return null;
            }

            $info = $this->infoFactory->fromDeclarations($this->declarationsIn($filePath), $name, $kind, $filePath);
            if ($info !== null) {
                $this->symbolsByPath[$filePath][] = [$name, $kind];
            }

            return $info;
        });
    }

    /**
     * Evict the file's cached symbols, so the next query re-reads disk and the
     * pre-change value is not restored (RFC 1 §5.2, §5.3).
     */
    public function invalidate(string $uri): void
    {
        $path = FileUri::toPath($uri);
        foreach ($this->symbolsByPath[$path] ?? [] as [$name, $kind]) {
            $this->cache->forget($name, $kind);
        }
        unset($this->symbolsByPath[$path]);
    }

    /**
     * Empty by contract: a prefix search over the PSR-4 tree needs a workspace walk
     * this backend does not do (RFC 1 §3, §5.3). Scoped to the tree, not to every
     * kind — the derived `autoload.files` index is a filter, not a walk.
     *
     * @return list<never>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        return [];
    }

    /**
     * A declaration at any depth counts, not just a top-level one: the shape most
     * `autoload.files` entries take is a polyfill declared inside an
     * `if (!function_exists(...))`, and that is a name the file validly declares.
     * This is the scan that derived the name -> file map the lookup arrived through,
     * so the two cannot disagree about what the file declares.
     */
    private function declarationsIn(string $filePath): FileDeclarations
    {
        $ast = $this->parser->parseFile($filePath);

        return $ast === null ? new FileDeclarations([], [], []) : $this->scanner->scan($ast);
    }
}
