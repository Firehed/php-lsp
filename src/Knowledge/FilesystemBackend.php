<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\InvalidatableInterface;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\NamespaceCatalogInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\PrefixSearchableInterface;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Parser\SourceFileReader;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

/**
 * A {@see SymbolSourceInterface} over PHP files on disk, resolved through
 * Composer's autoload maps: the workspace's own code, and vendored dependencies.
 * The same class serves both roles — the difference is only which autoload map
 * subset it is given, so one lookup mechanism covers both rather than two
 * hand-written copies.
 *
 * Lookup locates the file for a name and parses that one file — no
 * `vendor/` pre-index (RFC 1 §3, lazy-first). Results are held behind the
 * replaceable cache seam (RFC 1 §5.3): a file on disk is stable while unchanged, so
 * a resolved symbol is memoized. An on-disk change to a file is signalled through
 * {@see invalidate()} ({@see InvalidatableInterface}), which evicts that file's cached
 * symbols and drops cached namespace listings so the next query reflects disk
 * (RFC 1 §5.2, §5.3).
 *
 * Namespace enumeration is a directory listing through the same autoload map
 * ({@see NamespaceCatalogInterface}). Prefix search for class-likes is empty: a bare prefix
 * has no name→file map, so project-wide search over disk is the deferred
 * workspace-index scope (RFC 1 §3). Functions and constants are searched through the
 * autoload.files index ({@see PrefixSearchableInterface}), which is bounded and already in
 * memory.
 */
final class FilesystemBackend implements SymbolSourceInterface, InvalidatableInterface
{
    use BuildsInfoFromDeclarationsTrait;

    /**
     * The symbols derived from each file, recorded so invalidation can evict them.
     *
     * @var array<string, list<array{QualifiedName, NameKind}>>
     */
    private array $symbolsByPath = [];

    public function __construct(
        private readonly SymbolLocatorInterface $locator,
        private readonly NamespaceCatalogInterface $namespaces,
        private readonly SyntaxSourceInterface $parser,
        private readonly SourceFileReader $reader,
        private readonly DeclarationScanner $scanner,
        private readonly SymbolCache $cache,
        private readonly PrefixSearchableInterface $prefixSearch,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->lookup(
            $name->qualifiedName,
            NameKind::ClassLike,
            fn(FileDeclarations $d, string $p): ?ClassInfo => $this->classInfoFrom($d, $name, $p),
        );
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->lookup(
            $name->qualifiedName,
            $name->kind,
            fn(FileDeclarations $d, string $p): ?ConstantInfo => $this->constantInfoFrom($d, $name, $p),
        );
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->lookup(
            $name->qualifiedName,
            $name->kind,
            fn(FileDeclarations $d, string $p): ?FunctionInfo => $this->functionInfoFrom($d, $name, $p),
        );
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
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return $this->prefixSearch->searchByPrefix($prefix, NameKind::ClassLike);
    }

    /**
     * @return list<Symbol>
     */
    public function searchConstants(string $prefix): array
    {
        return $this->prefixSearch->searchByPrefix($prefix, NameKind::Constant);
    }

    /**
     * @return list<Symbol>
     */
    public function searchFunctions(string $prefix): array
    {
        return $this->prefixSearch->searchByPrefix($prefix, NameKind::Function_);
    }

    /**
     * @template T of ClassInfo|ConstantInfo|FunctionInfo
     * @param \Closure(FileDeclarations, string): ?T $build
     * @return ?T
     */
    private function lookup(QualifiedName $qname, NameKind $kind, \Closure $build): ?object
    {
        return $this->cache->remember(
            $qname,
            $kind,
            function () use ($qname, $kind, $build) {
                $filePath = $this->locator->locate($qname, $kind);
                if ($filePath === null) {
                    return null;
                }

                $declarations = $this->scanner->scanFile($filePath, $this->reader, $this->parser);
                $info = $build($declarations, $filePath);
                if ($info !== null) {
                    $this->symbolsByPath[$filePath][] = [$qname, $kind];
                }

                return $info;
            },
        );
    }
}
