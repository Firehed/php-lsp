<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Document\FileUri;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\DeclarationScanner;
use Firehed\PhpLsp\Index\FileDeclarations;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Resolution\NameKind;
use Psr\SimpleCache\CacheInterface;

/**
 * A {@see SymbolBackend} over PHP files on disk, resolved through Composer's
 * autoload maps: the workspace's own code, and vendored dependencies. The same
 * class serves both roles — the difference is only which autoload map subset it is
 * given (Plan 0002 §3a: the workspace/vendor precedence split), so one lookup
 * mechanism covers both rather than two hand-written copies.
 *
 * Class-like lookup locates the file for a name and parses that one file — no
 * `vendor/` pre-index (RFC 1 §3, lazy-first). Results are held behind the
 * replaceable cache seam (RFC 1 §5.3): a file on disk is stable while unchanged, so
 * a resolved class is memoized. An on-disk change to a file is signalled through
 * {@see invalidate()} ({@see Invalidatable}), which evicts that file's cached
 * class-likes and drops cached namespace listings so the next query reflects disk
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
     * The class-cache keys derived from each file, so an on-disk change to one
     * file evicts exactly its entries. The class cache is keyed by an opaque hash
     * of the FQN with no reverse mapping to a path, so the path→key relation is
     * recorded here as classes are cached.
     *
     * @var array<string, list<string>>
     */
    private array $cacheKeysByPath = [];

    public function __construct(
        private readonly SymbolLocator $locator,
        private readonly NamespaceCatalog $namespaces,
        private readonly ParserService $parser,
        private readonly ClassInfoFactory $factory,
        private readonly DeclarationScanner $scanner,
        private readonly CacheInterface $cache,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        $qualifiedName = QualifiedName::fromClassName($name);
        $cacheKey = SymbolCacheKey::for($qualifiedName, NameKind::ClassLike);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            assert($cached instanceof ClassInfo);
            return $cached;
        }

        $filePath = $this->locator->locate($qualifiedName, NameKind::ClassLike);
        if ($filePath === null) {
            return null;
        }

        $classInfo = $this->parseClassFrom($name, $filePath);
        if ($classInfo !== null) {
            $this->cache->set($cacheKey, $classInfo);
            $this->cacheKeysByPath[$filePath][] = $cacheKey;
        }

        return $classInfo;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        $cacheKey = SymbolCacheKey::for($name->qualifiedName, $name->kind());

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            assert($cached instanceof FunctionInfo);
            return $cached;
        }

        $filePath = $this->locator->locate($name->qualifiedName, $name->kind());
        if ($filePath === null) {
            return null;
        }

        $functionInfo = $this->parseFunctionFrom($name, $filePath);
        if ($functionInfo !== null) {
            $this->cache->set($cacheKey, $functionInfo);
            $this->cacheKeysByPath[$filePath][] = $cacheKey;
        }

        return $functionInfo;
    }

    /**
     * Evict the file's cached class-likes by their recorded keys and drop cached
     * namespace listings, so the next query re-reads disk and the pre-change value
     * is not restored (RFC 1 §5.2, §5.3).
     */
    public function invalidate(string $uri): void
    {
        $path = FileUri::toPath($uri);
        foreach ($this->cacheKeysByPath[$path] ?? [] as $cacheKey) {
            $this->cache->delete($cacheKey);
        }
        unset($this->cacheKeysByPath[$path]);

        if ($this->namespaces instanceof Invalidatable) {
            $this->namespaces->invalidate($uri);
        }

        // The autoload.files index is derived from disk too, so a change must reach
        // the locator or the name -> file map stays stale behind an evicted cache.
        if ($this->locator instanceof Invalidatable) {
            $this->locator->invalidate($uri);
        }
    }

    /**
     * Empty by contract: a prefix search over the PSR-4 tree needs a workspace walk
     * this backend does not do (RFC 1 §3, §5.3). Scoped to the tree, not to every
     * kind — the derived `autoload.files` index is a filter, not a walk.
     *
     * @return list<never>
     */
    public function searchClassLikes(string $prefix): array
    {
        return [];
    }

    private function parseFunctionFrom(FunctionName $name, string $filePath): ?FunctionInfo
    {
        $kind = $name->kind();
        $target = $kind->normalize($name->qualifiedName);

        foreach ($this->declarationsIn($filePath)->functions as $declaration) {
            if ($kind->normalize($declaration->name) === $target) {
                return FunctionInfo::fromNode($declaration->node, $filePath);
            }
        }

        return null;
    }

    private function parseClassFrom(ClassName $name, string $filePath): ?ClassInfo
    {
        $target = QualifiedName::fromClassName($name)->fullyQualifiedName();

        foreach ($this->declarationsIn($filePath)->classLikes as $declaration) {
            if ($declaration->name->fullyQualifiedName() === $target) {
                return $this->factory->fromAstNode($declaration->node, FileUri::fromPath($filePath));
            }
        }

        return null;
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
