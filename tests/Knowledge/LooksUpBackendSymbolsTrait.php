<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;

/**
 * Lookups against a {@see SymbolSourceInterface} by plain string, for tests that
 * name many symbols.
 */
trait LooksUpBackendSymbolsTrait
{
    private static function classLikeIn(SymbolSourceInterface $backend, string $fqn): ?ClassInfo
    {
        return $backend->lookupClassLike(ClasslikeName::fromFullyQualified($fqn));
    }

    private static function functionIn(SymbolSourceInterface $backend, string $fqn): ?FunctionInfo
    {
        return $backend->lookupFunction(FunctionName::fromFullyQualified($fqn));
    }

    /**
     * The lookup for whichever kind a data provider names.
     */
    private static function symbolOfKindIn(
        SymbolSourceInterface $backend,
        string $fqn,
        NameKind $kind,
    ): ?SymbolInfoInterface {
        return match ($kind) {
            NameKind::ClassLike => self::classLikeIn($backend, $fqn),
            NameKind::Constant => $backend->lookupConstant(ConstantName::fromFullyQualified($fqn)),
            NameKind::Function_ => self::functionIn($backend, $fqn),
        };
    }
}
