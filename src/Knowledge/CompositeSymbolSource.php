<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The {@see SymbolSourceInterface} read seam over a fixed-precedence list of
 * source implementations (RFC 1 §4.2, §5.3). Adding, removing, or reordering
 * a source is a change to the backend list here, with no change to any
 * consumer.
 *
 * Precedence is fixed and positional — the sources are passed in authority order
 * (open documents, then the workspace, then vendored dependencies, then the
 * built-ins), so for any symbol an open-document answer overrides the rest.
 * A lookup takes the first source that answers; an enumeration or search
 * merges every source, letting the earlier (more authoritative) one win a
 * name clash — a user's unsaved edit is honored over the cached file it shadows.
 */
final class CompositeSymbolSource implements SymbolSourceInterface
{
    /**
     * @param list<SymbolSourceInterface> $backends In descending precedence: the
     *        first that answers a lookup wins, and the first to report a name
     *        wins a merge. Readable so the coverage grid derives its rows from it.
     */
    public function __construct(
        public readonly array $backends,
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return NamespaceContents::merge(array_map(
            static fn(SymbolSourceInterface $backend): NamespaceContents => $backend->childrenOf($namespace),
            $this->backends,
        ));
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        foreach ($this->backends as $backend) {
            $info = $backend->lookupClassLike($name);
            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        foreach ($this->backends as $backend) {
            $info = $backend->lookupConstant($name);
            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        foreach ($this->backends as $backend) {
            $info = $backend->lookupFunction($name);
            if ($info !== null) {
                return $info;
            }
        }

        return null;
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

    private static function normalizeKey(string $fqn): string
    {
        return NameKind::ClassLike->normalize(QualifiedName::fromFullyQualified($fqn));
    }
}
