<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Index\NamespaceContents;
use Firehed\PhpLsp\Index\Symbol;
use Firehed\PhpLsp\Knowledge\NamespaceName;
use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * An in-memory {@see SymbolBackend} configured with fixed answers, so
 * {@see \Firehed\PhpLsp\Tests\Knowledge\CompositeSymbolSourceTest} can prove the
 * composite's precedence and merge behavior without standing up real sources.
 */
final class FakeSymbolBackend implements SymbolBackend
{
    /**
     * @param array<string, ClassInfo> $classLikes Lowercased FQN -> info
     * @param array<string, NamespaceContents> $namespaces Path -> contents
     * @param list<Symbol> $searchResults Returned (prefix-filtered on short name)
     * @param array<string, FunctionInfo> $functions Lowercased FQN -> info
     */
    public function __construct(
        private readonly array $classLikes = [],
        private readonly array $namespaces = [],
        private readonly array $searchResults = [],
        private readonly array $functions = [],
    ) {
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces[$namespace->path] ?? new NamespaceContents();
    }

    public function lookupClassLike(ClassName $name): ?ClassInfo
    {
        return $this->classLikes[strtolower(ltrim($name->fqn, '\\'))] ?? null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->functions[strtolower($name->fullyQualifiedName())] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return array_values(array_filter(
            $this->searchResults,
            static fn(Symbol $symbol): bool => str_starts_with(
                strtolower($symbol->name),
                strtolower($prefix),
            ),
        ));
    }
}
