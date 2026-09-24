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
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;

/**
 * The per-kind lookups of {@see SymbolSourceInterface} for a backend that resolves
 * by name and kind. The kind selects what is resolved, and each kind resolves to
 * one concrete info type, so narrowing the marker type here is exact.
 */
trait LooksUpByKindTrait
{
    public function lookupClassLike(ClasslikeName $name): ?ClassInfo
    {
        $info = $this->lookup($name->qualifiedName, $name->kind);
        assert($info === null || $info instanceof ClassInfo);

        return $info;
    }

    public function lookupConstant(ConstantName $name): ?ConstantInfo
    {
        $info = $this->lookup($name->qualifiedName, $name->kind);
        assert($info === null || $info instanceof ConstantInfo);

        return $info;
    }

    public function lookupFunction(FunctionName $name): ?FunctionInfo
    {
        $info = $this->lookup($name->qualifiedName, $name->kind);
        assert($info === null || $info instanceof FunctionInfo);

        return $info;
    }

    /**
     * Full metadata for the symbol $name names as a $kind, or null when this
     * backend cannot reach such a declaration.
     */
    abstract private function lookup(QualifiedName $name, NameKind $kind): ?SymbolInfoInterface;
}
