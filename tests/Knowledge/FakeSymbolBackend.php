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
 * An in-memory {@see SymbolSourceInterface} configured with fixed answers, so
 * {@see \Firehed\PhpLsp\Tests\Knowledge\CompositeSymbolSourceTest} can prove
 * the composite's precedence and merge behavior without standing up real sources.
 */
final class FakeSymbolBackend implements SymbolSourceInterface
{
    /** @var array<string, ClassInfo> */
    private array $classesByKey = [];

    /** @var array<string, ConstantInfo> */
    private array $constantsByKey = [];

    /** @var array<string, FunctionInfo> */
    private array $functionsByKey = [];

    /**
     * @param list<ClassInfo> $classes
     * @param list<ConstantInfo> $constants
     * @param list<FunctionInfo> $functions
     * @param array<string, NamespaceContents> $namespaces Path -> contents
     * @param list<Symbol> $classLikeSearchResults Returned (prefix-filtered on short name) by searchClassLikes
     * @param list<Symbol> $constantSearchResults Returned (prefix-filtered on short name) by searchConstants
     * @param list<Symbol> $functionSearchResults Returned (prefix-filtered on short name) by searchFunctions
     */
    public function __construct(
        array $classes = [],
        array $constants = [],
        array $functions = [],
        private readonly array $namespaces = [],
        private readonly array $classLikeSearchResults = [],
        private readonly array $constantSearchResults = [],
        private readonly array $functionSearchResults = [],
    ) {
        foreach ($classes as $info) {
            $this->classesByKey[NameKind::ClassLike->keyFor($info->name->qualifiedName)] = $info;
        }
        foreach ($constants as $info) {
            $this->constantsByKey[NameKind::Constant->keyFor($info->name->qualifiedName)] = $info;
        }
        foreach ($functions as $info) {
            $this->functionsByKey[NameKind::Function_->keyFor($info->name->qualifiedName)] = $info;
        }
    }

    public function childrenOf(NamespaceName $namespace): NamespaceContents
    {
        return $this->namespaces[$namespace->path] ?? new NamespaceContents();
    }

    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        return $this->classesByKey[NameKind::ClassLike->keyFor($name->qualifiedName)] ?? null;
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        return $this->constantsByKey[$name->kind->keyFor($name->qualifiedName)] ?? null;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        return $this->functionsByKey[$name->kind->keyFor($name->qualifiedName)] ?? null;
    }

    /**
     * @return list<Symbol>
     */
    public function searchClassLikes(string $prefix): array
    {
        return self::filterByPrefix($this->classLikeSearchResults, $prefix);
    }

    /**
     * @return list<Symbol>
     */
    public function searchConstants(string $prefix): array
    {
        return self::filterByPrefix($this->constantSearchResults, $prefix);
    }

    /**
     * @return list<Symbol>
     */
    public function searchFunctions(string $prefix): array
    {
        return self::filterByPrefix($this->functionSearchResults, $prefix);
    }

    /**
     * @param list<Symbol> $symbols
     * @return list<Symbol>
     */
    private static function filterByPrefix(array $symbols, string $prefix): array
    {
        return array_values(array_filter(
            $symbols,
            static fn(Symbol $symbol): bool => str_starts_with(
                strtolower($symbol->name),
                strtolower($prefix),
            ),
        ));
    }
}
