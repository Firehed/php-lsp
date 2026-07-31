<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\ClassLocator;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
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
 * a resolved class is memoized. The workspace/vendor cache *policy* split — vendor
 * cached hard, workspace invalidated on change — is Step 3a's later slice; here both
 * carry the default in-process cache they had before.
 *
 * Namespace enumeration is a directory listing through the same autoload map
 * ({@see NamespaceCatalog}). Prefix search is empty: a name→file map exists for
 * classes, but a bare prefix has no such map, so project-wide search over disk is
 * the deferred workspace-index scope (RFC 1 §3), not an unbounded walk here.
 */
final class FilesystemBackend implements SymbolBackend
{
    public function __construct(
        private readonly ClassLocator $locator,
        private readonly NamespaceCatalog $namespaces,
        private readonly ParserService $parser,
        private readonly ClassInfoFactory $factory,
        private readonly CacheInterface $cache,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces->childrenOf($namespace->path);
    }

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        $cacheKey = CacheKey::from(strtolower(ltrim($name->fqn, '\\')));

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            assert($cached instanceof ClassInfo);
            return $cached;
        }

        $classInfo = $this->locateAndParse($name);
        if ($classInfo !== null) {
            $this->cache->set($cacheKey, $classInfo);
        }

        return $classInfo;
    }

    /**
     * Empty by contract: project-wide prefix search over on-disk files needs an
     * index this backend does not build (RFC 1 §3, §5.3).
     *
     * @return list<never>
     */
    public function searchClassLikes(string $prefix): array
    {
        return [];
    }

    private function locateAndParse(ClassName $name): ?ClassInfo
    {
        $filePath = $this->locator->locate($name);
        if ($filePath === null || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            // @codeCoverageIgnoreStart
            // A readable file that then fails to read is an IO race, not a code path
            // the corpus can drive; it degrades to "not found" rather than crashing.
            return null;
            // @codeCoverageIgnoreEnd
        }

        $uri = 'file://' . $filePath;
        $document = new TextDocument($uri, 'php', 0, $content);
        $ast = $this->parser->parse($document);
        if ($ast === null) {
            // @codeCoverageIgnoreStart
            // ParserService yields null only when a parse throws despite error
            // recovery — unreachable for a located, well-formed file (RFC 1 §9).
            return null;
            // @codeCoverageIgnoreEnd
        }

        $node = $this->findClassInAst($name->fqn, $ast);
        if ($node === null) {
            return null;
        }

        return $this->factory->fromAstNode($node, $uri);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findClassInAst(
        string $className,
        array $ast,
    ): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;
            private string $namespace = '';

            public function __construct(private readonly string $className)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }

                if (
                    $node instanceof Stmt\Class_
                    || $node instanceof Stmt\Interface_
                    || $node instanceof Stmt\Trait_
                    || $node instanceof Stmt\Enum_
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

                    if ($fqn === $this->className || $name === $this->className) {
                        $this->found = $node;
                        return NodeTraverser::STOP_TRAVERSAL;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }
}
