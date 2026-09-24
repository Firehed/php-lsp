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
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;

/**
 * The {@see SymbolSourceInterface} composite over a fixed-precedence list of
 * {@see SymbolSourceInterface}s (RFC 1 §4.2, §5.3). This is the single place symbol
 * sources are composed: adding, removing, or reordering a source is a change to
 * the backend list here, with no change to any consumer.
 *
 * Precedence is fixed and positional — the backends are passed in authority order
 * (open documents, then disk, then the built-ins), so for any symbol an
 * open-document answer overrides the rest
 * (RFC 1 §5.3). A lookup takes the first backend that answers; an enumeration or
 * search merges every backend, letting the earlier (more authoritative) one win a
 * name clash — a user's unsaved edit is honored over the cached file it shadows.
 */
final class CompositeSymbolSource implements SymbolSourceInterface
{
    /**
     * @param list<SymbolSourceInterface> $backends In descending precedence: the first
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
            static fn(SymbolSourceInterface $backend): NamespaceContents => $backend->childrenOf($namespace),
            $this->backends,
        ));
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->firstAnswer(
            static fn(SymbolSourceInterface $backend): ?ClassInfo => $backend->lookupClassLike($name),
        );
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->firstAnswer(
            static fn(SymbolSourceInterface $backend): ?ConstantInfo => $backend->lookupConstant($name),
        );
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->firstAnswer(
            static fn(SymbolSourceInterface $backend): ?FunctionInfo => $backend->lookupFunction($name),
        );
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $byFqn = [];
        foreach ($this->backends as $backend) {
            foreach ($backend->search($prefix, $kind) as $symbol) {
                $byFqn[self::normalizeKey($symbol->fullyQualifiedName, $kind)] ??= $symbol;
            }
        }

        return array_values($byFqn);
    }

    /**
     * @template T of SymbolInfoInterface
     * @param callable(SymbolSourceInterface): ?T $lookup
     * @return ?T
     */
    private function firstAnswer(callable $lookup): ?SymbolInfoInterface
    {
        foreach ($this->backends as $backend) {
            $info = $lookup($backend);
            if ($info !== null) {
                return $info;
            }
        }

        return null;
    }

    private static function normalizeKey(string $fqn, NameKind $kind): string
    {
        return $kind->normalize(QualifiedName::fromFullyQualified($fqn));
    }
}
