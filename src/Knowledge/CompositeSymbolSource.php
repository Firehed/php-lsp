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
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\Symbol;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;

/**
 * The {@see SymbolSourceInterface} composite over a fixed backend precedence
 * (RFC 1 §4.2, §5.3). Adding, removing, or reordering a backend is a change to
 * this constructor, with no change to any consumer.
 *
 * Precedence is fixed and positional: an open-document answer overrides the
 * autoload.files set, which overrides the file on disk resolved through
 * Composer's maps, which overrides the built-ins. A lookup takes the first
 * backend that answers; an enumeration or search merges every backend, letting
 * the earlier (more authoritative) one win a name clash — a user's unsaved
 * edit is honored over the cached file it shadows.
 *
 * The disk and built-in slots are typed on the interface because a cache
 * decorator arrives there in wiring; the composite has no business knowing.
 */
final class CompositeSymbolSource implements SymbolSourceInterface
{
    /**
     * @var list<SymbolSourceInterface> Backends in descending precedence.
     *      Readable so the §5.1 coverage grid derives its rows from it.
     */
    public readonly array $backends;

    public function __construct(
        OpenDocumentBackend $openDocuments,
        AutoloadFilesBackend $autoloadFiles,
        SymbolSourceInterface $disk,
        SymbolSourceInterface $builtin,
    ) {
        $this->backends = [$openDocuments, $autoloadFiles, $disk, $builtin];
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
