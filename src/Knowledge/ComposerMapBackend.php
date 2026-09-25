<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Composer\Autoload\ClassLoader;
use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ComposerAutoloadMap;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\Location;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\PrefixMatcher;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * A {@see SymbolSourceInterface} over PHP files on disk, resolved through
 * Composer's autoload maps: the workspace's own code and its vendored
 * dependencies, with the precedence Composer's own autoloader applies.
 *
 * `childrenOf` and `search` both read one derived index: the classmap keys
 * plus one walk of every PSR-4 and PSR-0 root, each walked name confirmed
 * through Composer's own loader so runtime rules apply verbatim. The index is
 * built once on first use — a project without a `vendor/` never pays for it —
 * and adjusted one name at a time when a watched file changes (RFC 1 §5.2,
 * §5.3). Names declared in `autoload.files` entries are the separate
 * {@see AutoloadFilesBackend}'s concern.
 *
 * Lookup is a name-to-file resolve through Composer, then a parse of that one
 * file. It stays out of the index because the index has only names (RFC 1 §3,
 * lazy-first). Only class-likes are addressable through Composer's maps;
 * functions and constants have no name -> file route here.
 */
final class ComposerMapBackend implements SymbolSourceInterface, InvalidatableInterface
{
    use LooksUpByKindTrait;

    /** @var array<string, NamespaceContents>|null Lowercase namespace -> contents; null until the index is built */
    private ?array $byNamespace = null;

    /** @var array<string, CatalogSymbol> Kind-qualified key -> catalog entry */
    private array $catalog = [];

    /** @var array<string, string> Kind-qualified key -> real path of the file that declares it */
    private array $pathByKey = [];

    /** @var array<string, string> Real path -> the FQN the walk derived for it, so a change on that path can adjust one name */
    private array $fqnByWalkedPath = [];

    public function __construct(
        private readonly ComposerAutoloadMap $map,
        private readonly SyntaxSourceInterface $parser,
        private readonly SourceFileReader $reader,
        private readonly DeclarationSymbolInfoFactory $infoFactory,
        private readonly DeclarationScanner $scanner,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $this->ensureIndex();

        return $this->byNamespace[$namespace->normalize()] ?? new NamespaceContents();
    }

    /**
     * A watched-file event adjusts one name rather than dropping the whole
     * index. The path's previously walked name (if any) is removed, and the
     * name Composer's loader now confirms at that path (if any) is added
     * (RFC 1 §5.2, §5.3). Classmap entries are static from this backend's
     * view: they change only when the map itself is regenerated.
     */
    public function invalidate(string $uri): void
    {
        if ($this->byNamespace === null) {
            return;
        }

        $path = FileUri::toPath($uri);
        $prior = $this->fqnByWalkedPath[$path] ?? null;
        $current = $this->deriveWalkedName($path, $this->buildLoader());

        if ($prior === $current) {
            return;
        }

        if ($prior !== null) {
            unset($this->fqnByWalkedPath[$path]);
            $priorKey = NameKind::ClassLike->keyFor(QualifiedName::fromFullyQualified($prior));
            unset($this->catalog[$priorKey], $this->pathByKey[$priorKey]);
        }
        if ($current !== null) {
            $this->fqnByWalkedPath[$path] = $current;
            $this->addToCatalog($current, $path);
        }

        $this->byNamespace = NamespaceContents::indexByNamespace($this->catalog);
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        if (!$kind->isClassLike()) {
            return [];
        }

        $this->ensureIndex();

        $results = [];
        foreach ($this->catalog as $key => $symbol) {
            $short = NamespaceName::shortNameOf($symbol->fullyQualifiedName);
            if (!PrefixMatcher::matches($short, $prefix)) {
                continue;
            }
            $results[$key] = new Symbol(
                name: $short,
                fullyQualifiedName: $symbol->fullyQualifiedName,
                kind: SymbolKind::Class_,
                location: new Location(FileUri::fromPath($this->pathByKey[$key]), 0, 0, 0, 0),
                nameKind: NameKind::ClassLike,
            );
        }

        return array_values($results);
    }

    private function addToCatalog(string $fqn, string $path): void
    {
        $symbol = new CatalogSymbol($fqn, NameKind::ClassLike);
        $key = $symbol->key();
        if (array_key_exists($key, $this->catalog)) {
            return;
        }
        $this->catalog[$key] = $symbol;
        $this->pathByKey[$key] = $path;
    }

    private function buildIndex(): void
    {
        $this->catalog = [];
        $this->pathByKey = [];
        $this->fqnByWalkedPath = [];

        foreach ($this->map->classMap() as $fqn => $path) {
            $this->addToCatalog($fqn, $path);
        }

        $loader = $this->buildLoader();

        // Prefixes are walked longest-first so a file reachable through
        // several prefixes (overlapping PSR-4 layouts like
        // `App\Tests\ => tests` and `App\ => [src, tests]`) is filed once,
        // under the most specific prefix — the one Composer itself resolves
        // first (`findFile` iterates prefixes in reverse-length order).
        // The base-prefix candidate would name a file whose class the file
        // does not declare, so it must not enter the index.
        foreach (self::orderedByPrefixLength($this->map->psr4Prefixes()) as $prefix => $directories) {
            $prefixTrimmed = trim($prefix, '\\');
            foreach ($directories as $directory) {
                foreach (self::walkPhpFiles($directory) as $file) {
                    if (array_key_exists($file, $this->fqnByWalkedPath)) {
                        continue;
                    }
                    $candidate = self::psr4Candidate($prefixTrimmed, $directory, $file);
                    if ($candidate !== null && $loader->findFile($candidate) === $file) {
                        $this->addToCatalog($candidate, $file);
                        $this->fqnByWalkedPath[$file] = $candidate;
                    }
                }
            }
        }
        foreach (self::orderedByPrefixLength($this->map->psr0Prefixes()) as $prefix => $directories) {
            $prefixTrimmed = trim($prefix, '\\');
            foreach ($directories as $directory) {
                foreach (self::walkPhpFiles($directory) as $file) {
                    if (array_key_exists($file, $this->fqnByWalkedPath)) {
                        continue;
                    }
                    $candidate = self::psr0Candidate($prefixTrimmed, $directory, $file);
                    if ($candidate !== null && $loader->findFile($candidate) === $file) {
                        $this->addToCatalog($candidate, $file);
                        $this->fqnByWalkedPath[$file] = $candidate;
                    }
                }
            }
        }

        $this->byNamespace = NamespaceContents::indexByNamespace($this->catalog);
    }

    /**
     * @param array<string, list<string>> $prefixes
     * @return array<string, list<string>>
     */
    private static function orderedByPrefixLength(array $prefixes): array
    {
        uksort($prefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    /**
     * Composer's own `ClassLoader` is built from the map. `findFile` remembers
     * every miss for the loader's lifetime, so callers throw the instance away
     * once they are done confirming a batch: otherwise a file created after
     * the first miss would never be seen.
     */
    private function buildLoader(): ClassLoader
    {
        $loader = new ClassLoader();
        foreach ($this->map->psr4Prefixes() as $prefix => $directories) {
            $loader->setPsr4($prefix, $directories);
        }
        foreach ($this->map->psr0Prefixes() as $prefix => $directories) {
            $loader->set($prefix, $directories);
        }
        $loader->addClassMap($this->map->classMap());

        return $loader;
    }

    private function deriveWalkedName(string $path, ClassLoader $loader): ?string
    {
        if (!str_ends_with($path, '.php') || !is_file($path)) {
            return null;
        }

        foreach ($this->map->psr4Prefixes() as $prefix => $directories) {
            $prefixTrimmed = trim($prefix, '\\');
            foreach ($directories as $directory) {
                $candidate = self::psr4Candidate($prefixTrimmed, $directory, $path);
                if ($candidate !== null && $loader->findFile($candidate) === $path) {
                    return $candidate;
                }
            }
        }
        foreach ($this->map->psr0Prefixes() as $prefix => $directories) {
            $prefixTrimmed = trim($prefix, '\\');
            foreach ($directories as $directory) {
                $candidate = self::psr0Candidate($prefixTrimmed, $directory, $path);
                if ($candidate !== null && $loader->findFile($candidate) === $path) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function ensureIndex(): void
    {
        if ($this->byNamespace === null) {
            $this->buildIndex();
        }
    }

    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface
    {
        if (!$kind->isClassLike()) {
            return null;
        }

        $file = $this->buildLoader()->findFile($name->fullyQualifiedName());
        if ($file === false) {
            return null;
        }

        $declarations = $this->scanner->scanFile($file, $this->reader, $this->parser);

        return $this->infoFactory->fromDeclarations($declarations, $name, $kind, $file);
    }

    /**
     * PSR-4 strips the prefix from the path: `App\Model` under prefix `App\`
     * maps to `<dir>/Model.php`. Returns the candidate FQN when $file sits
     * under $directory as a `.php` file, else null.
     */
    private static function psr4Candidate(string $prefixTrimmed, string $directory, string $file): ?string
    {
        $relative = self::relativePhpPath($directory, $file);
        if ($relative === null) {
            return null;
        }

        $withinPrefix = str_replace('/', '\\', $relative);

        return NamespaceName::join($prefixTrimmed, $withinPrefix);
    }

    /**
     * PSR-0 nests the prefix inside the directory: `Psr0\Sub\Item` under
     * prefix `Psr0` maps to `<dir>/Psr0/Sub/Item.php`. Returns null when $file
     * is not under the prefix's directory branch.
     */
    private static function psr0Candidate(string $prefixTrimmed, string $directory, string $file): ?string
    {
        $relative = self::relativePhpPath($directory, $file);
        if ($relative === null) {
            return null;
        }

        $candidate = str_replace('/', '\\', $relative);
        // A file outside the prefix's own subtree can never be confirmed
        // through the loader; skip it before the string swap.
        if ($prefixTrimmed !== '' && !str_starts_with($candidate, $prefixTrimmed . '\\')) {
            return null;
        }

        return $candidate;
    }

    /**
     * The path of $file below $directory, with `.php` stripped, or null when
     * $file does not sit inside $directory. Callers guarantee the extension.
     */
    private static function relativePhpPath(string $directory, string $file): ?string
    {
        $normalized = rtrim($directory, '/') . '/';
        if (!str_starts_with($file, $normalized)) {
            return null;
        }

        return substr($file, strlen($normalized), -4);
    }

    /**
     * @return iterable<string> Real paths of every `.php` file under $directory.
     */
    private static function walkPhpFiles(string $directory): iterable
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                yield from self::walkPhpFiles($path);
                continue;
            }
            if (str_ends_with($entry, '.php') && is_file($path)) {
                yield $path;
            }
        }
    }
}
