<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Cache\Warmable;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Resolution\NameKind;
use Psr\SimpleCache\CacheInterface;

/**
 * Locates a declaration through Composer's autoload configuration, for all three of
 * PHP's symbol namespaces.
 *
 * The two halves work differently because Composer's data does:
 *
 * - **Class-likes** have a name -> file map (PSR-4, PSR-0, classmap), so a lookup is
 *   arithmetic on the name — no file is read.
 * - **Functions and constants** have no such map and never can: nothing about the
 *   name `Foo\bar` says which file declares it. Their declarations are reachable
 *   only in the `autoload.files` set, which is explicit and usually tiny, so a
 *   name -> file index is *derived* by parsing that set once (Plan 0002 §3).
 *
 * That index is built lazily, on the first function or constant lookup, so a server
 * that never resolves one never pays for it. {@see warm()} triggers the same build
 * ahead of time, which is latency only — an unwarmed locator answers identically.
 *
 * Each file's scan is held behind the replaceable cache seam (RFC 1 §5.3) keyed by
 * its path, so {@see invalidate()} re-reads exactly the file that changed on disk
 * rather than the whole set (RFC 1 §5.2).
 */
final class ComposerSymbolLocator implements SymbolLocator, Invalidatable, Warmable
{
    /**
     * Qualified name -> declaring file, per symbol namespace. Null until derived;
     * dropped wholesale on invalidation, when it is rebuilt from the per-file scans
     * still cached for every file that did not change.
     *
     * @var ?array{function: array<string, string>, constant: array<string, string>}
     */
    private ?array $index = null;

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        private readonly ParserService $parser,
        private readonly DeclarationScanner $scanner,
        private readonly CacheInterface $cache,
    ) {
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        if ($kind === NameKind::ClassLike) {
            $file = $this->map->classLoader()->findFile($name->fullyQualifiedName());

            return $file !== false ? $file : null;
        }

        $index = $this->index ??= $this->buildIndex();

        return $index[self::partitionFor($kind)][self::nameKey($name, $kind)] ?? null;
    }

    /**
     * Drop the changed file's cached scan and the derived index. The next lookup
     * rebuilds, re-reading that one file and reusing the cached scans of the rest
     * (RFC 1 §5.2, §5.3).
     */
    public function invalidate(string $uri): void
    {
        $path = self::pathFromUri($uri);
        if (!in_array($path, $this->map->autoloadFiles(), true)) {
            return;
        }

        $this->cache->delete(CacheKey::from($path));
        $this->index = null;
    }

    public function warm(): void
    {
        $this->index ??= $this->buildIndex();
    }

    /**
     * @return array{function: array<string, string>, constant: array<string, string>}
     */
    private function buildIndex(): array
    {
        $index = ['function' => [], 'constant' => []];

        foreach ($this->map->autoloadFiles() as $path) {
            $declarations = $this->declarationsIn($path);

            foreach ($declarations->functions as $name) {
                // First declaration wins: PHP cannot load two files declaring the
                // same function, so a later match is a stale map, not an override.
                $index['function'][self::nameKey($name, NameKind::Function_)] ??= $path;
            }
            foreach ($declarations->constants as $name) {
                $index['constant'][self::nameKey($name, NameKind::Constant)] ??= $path;
            }
        }

        return $index;
    }

    private function declarationsIn(string $path): FileDeclarations
    {
        $cacheKey = CacheKey::from($path);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            assert($cached instanceof FileDeclarations);
            return $cached;
        }

        $declarations = $this->scan($path);
        $this->cache->set($cacheKey, $declarations);

        return $declarations;
    }

    private function scan(string $path): FileDeclarations
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            // An autoload map naming a file that is not there is a stale map — a
            // `composer` operation part-way through, or a removed dependency. The
            // remaining files are still scanned rather than the whole set failing.
            return new FileDeclarations();
        }

        $ast = $this->parser->parse(new TextDocument('file://' . $path, 'php', 0, $content));

        return $this->scanner->scan($ast ?? []);
    }

    /**
     * PHP matches function names case-insensitively and constant names case-
     * sensitively, so the key each is stored under follows its own kind.
     */
    private static function nameKey(QualifiedName $name, NameKind $kind): string
    {
        $fqn = $name->fullyQualifiedName();

        return $kind->isCaseSensitive() ? $fqn : strtolower($fqn);
    }

    /**
     * @return 'function'|'constant'
     */
    private static function partitionFor(NameKind $kind): string
    {
        return $kind === NameKind::Function_ ? 'function' : 'constant';
    }

    /**
     * The filesystem path a `file://` URI addresses, matching the unencoded paths
     * the autoload map carries. A path that is not a `file://` URI is unchanged.
     */
    private static function pathFromUri(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }

        return rawurldecode(substr($uri, strlen('file://')));
    }
}
