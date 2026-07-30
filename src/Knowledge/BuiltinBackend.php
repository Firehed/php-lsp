<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;

/**
 * The lowest-precedence {@see SymbolBackend}: the symbols built into PHP and its
 * loaded extensions, described through reflection. It is consulted only after the
 * open-document, workspace, and vendor backends, so a name any of them can resolve
 * never reaches reflection (RFC 1 §5.3).
 *
 * Built-ins are fixed for a given target environment, so a resolved class is cached
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
        private readonly ClassInfoFactory $factory,
        private readonly NamespaceCatalog $namespaces,
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

        try {
            $classInfo = $this->factory->fromReflection(new ReflectionClass($name->fqn));
        } catch (ReflectionException) {
            return null;
        }
        $this->cache->set($cacheKey, $classInfo);

        return $classInfo;
    }

    /**
     * @return list<never>
     */
    public function searchClassLikes(string $prefix): array
    {
        return [];
    }
}
