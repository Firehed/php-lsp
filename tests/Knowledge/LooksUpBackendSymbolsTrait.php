<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolBackend;

/**
 * Typed lookups against a {@see SymbolBackend}, whose own method is
 * kind-parameterized and returns the {@see \Firehed\PhpLsp\Domain\SymbolInfo} marker
 * (Plan 0002 §5.6).
 *
 * The narrowing each helper performs is the assertion
 * {@see \Firehed\PhpLsp\Knowledge\CompositeSymbolSource} makes in production, so
 * every call site here also pins the kind → info-type contract: a backend answering
 * a function lookup with a `ClassInfo` fails at the call, not somewhere downstream.
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
