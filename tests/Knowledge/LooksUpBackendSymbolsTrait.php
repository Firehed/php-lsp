<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * Typed lookups against a {@see SymbolBackend}, narrowing as `CompositeSymbolSource`
 * does in production so every call site also pins the kind → info-type contract.
 */
trait LooksUpBackendSymbolsTrait
{
    private static function classLikeIn(SymbolBackend $backend, string $fqn): ?ClassInfo
    {
        $info = $backend->lookup(QualifiedName::fromFullyQualified($fqn), NameKind::ClassLike);
        if ($info === null) {
            return null;
        }
        self::assertInstanceOf(ClassInfo::class, $info, 'a class-like lookup must answer with ClassInfo');

        return $info;
    }

    private static function functionIn(SymbolBackend $backend, string $fqn): ?FunctionInfo
    {
        $info = $backend->lookup(QualifiedName::fromFullyQualified($fqn), NameKind::Function_);
        if ($info === null) {
            return null;
        }
        self::assertInstanceOf(FunctionInfo::class, $info, 'a function lookup must answer with FunctionInfo');

        return $info;
    }
}
