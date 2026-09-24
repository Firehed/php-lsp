<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;

/**
 * Records how often each query reaches the wrapped source, so that caching can
 * be asserted on rather than assumed.
 */
final class CountingSymbolSource implements SymbolSourceInterface
{
    /** @var array<string, int> Method -> calls */
    private array $calls = [];

    public function __construct(
        private readonly SymbolSourceInterface $inner,
    ) {
    }

    public function callsTo(string $method): int
    {
        return $this->calls[$method] ?? 0;
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        $this->count(__FUNCTION__);

        return $this->inner->childrenOf($namespace);
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        $this->count(__FUNCTION__);

        return $this->inner->lookupClassLike($name);
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        $this->count(__FUNCTION__);

        return $this->inner->lookupConstant($name);
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        $this->count(__FUNCTION__);

        return $this->inner->lookupFunction($name);
    }

    /**
     * @return list<Symbol>
     */
    public function search(string $prefix, NameKind $kind): array
    {
        $this->count(__FUNCTION__);

        return $this->inner->search($prefix, $kind);
    }

    private function count(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
    }
}
