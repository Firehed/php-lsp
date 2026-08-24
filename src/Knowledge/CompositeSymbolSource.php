<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\GlobalConstantName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfo;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The {@see SymbolSource} read seam over a fixed-precedence list of
 * {@see SymbolBackend}s (RFC 1 §4.2, §5.3). This is the single place symbol
 * sources are composed: adding, removing, or reordering a source is a change to
 * the backend list here, with no change to any consumer.
 *
 * Precedence is fixed and positional — the backends are passed in authority order
 * (open documents, then the workspace, then vendored dependencies, then the
 * built-ins), so for any symbol an open-document answer overrides the rest
 * (RFC 1 §5.3). A lookup takes the first backend that answers; an enumeration or
 * search merges every backend, letting the earlier (more authoritative) one win a
 * name clash — a user's unsaved edit is honored over the cached file it shadows.
 *
 * The subtype query walks the type graph over {@see lookupClassLike}, so every
 * edge it follows is resolved through the same precedence as a direct lookup: an
 * open document's class may extend a vendored one and the walk crosses the seam
 * transparently.
 */
final class CompositeSymbolSource implements SymbolSource
{
    /**
     * @param list<SymbolBackend> $backends In descending precedence: the first
     *        that answers a lookup wins, and the first to report a name wins a
     *        merge. Readable so the §5.1 coverage grid derives its rows from it.
     */
    public function __construct(
        public readonly array $backends,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return NamespaceContents::merge(array_map(
            static fn(SymbolBackend $backend): NamespaceContents => $backend->childrenOf($namespace),
            $this->backends,
        ));
    }

    public function isSubclassOf(ClassName $class, ClassName $potentialParent): bool
    {
        $classInfo = $this->lookupClassLike($class);
        if ($classInfo === null) {
            return false;
        }

        $targetKey = self::normalizeKey($potentialParent->fqn);
        $visited = [self::normalizeKey($class->fqn) => true];

        return $this->checkInheritance($classInfo, $targetKey, $visited);
    }

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        $info = $this->lookup(QualifiedName::fromClassName($name), NameKind::ClassLike);
        assert($info === null || $info instanceof ClassInfo);

        return $info;
    }

    public function lookupConstant(GlobalConstantName $name): ?ConstantInfo
    {
        $info = $this->lookup($name->qualifiedName, $name->kind());
        assert($info === null || $info instanceof ConstantInfo);

        return $info;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        $info = $this->lookup($name->qualifiedName, $name->kind());
        assert($info === null || $info instanceof FunctionInfo);

        return $info;
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $byFqn = [];
        foreach ($this->backends as $backend) {
            foreach ($backend->search($prefix, $kind) as $symbol) {
                $byFqn[self::normalizeKey($symbol->fullyQualifiedName)] ??= $symbol;
            }
        }

        return array_values($byFqn);
    }

    /**
     * Answers with the marker type; each caller above narrows it back to a concrete
     * one. That is the O(kinds) narrowing Plan 0002 §5.6 trades against a lookup
     * method per kind on every backend.
     */
    private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfo
    {
        foreach ($this->backends as $backend) {
            $info = $backend->lookup($name, $kind);
            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $visited
     */
    private function checkInheritance(ClassInfo $classInfo, string $targetKey, array &$visited): bool
    {
        if ($classInfo->parent !== null) {
            $parentKey = self::normalizeKey($classInfo->parent->fqn);
            if ($parentKey === $targetKey) {
                return true;
            }
            if (!array_key_exists($parentKey, $visited)) {
                $visited[$parentKey] = true;
                $parentInfo = $this->lookupClassLike($classInfo->parent);
                if ($parentInfo !== null && $this->checkInheritance($parentInfo, $targetKey, $visited)) {
                    return true;
                }
            }
        }

        foreach ($classInfo->interfaces as $interface) {
            $interfaceKey = self::normalizeKey($interface->fqn);
            if ($interfaceKey === $targetKey) {
                return true;
            }
            if (!array_key_exists($interfaceKey, $visited)) {
                $visited[$interfaceKey] = true;
                $interfaceInfo = $this->lookupClassLike($interface);
                if ($interfaceInfo !== null && $this->checkInheritance($interfaceInfo, $targetKey, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Class-like identity under the kind's case rule; every name this class keys
     * or compares is a class-like. `fromFullyQualified` drops a leading `\`.
     */
    private static function normalizeKey(string $fqn): string
    {
        return NameKind::ClassLike->normalize(QualifiedName::fromFullyQualified($fqn));
    }
}
