<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Knowledge;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;

/**
 * String-FQN wrappers around the three typed lookups, so tests can name a
 * symbol by its string form without repeating the ClasslikeName/ConstantName/
 * FunctionName construction each time.
 */
trait LooksUpBackendSymbolsTrait
{
    private static function classLikeIn(SymbolSourceInterface $backend, string $fqn): ?ClassInfo
    {
        return $backend->lookupClassLike(ClasslikeName::fromFullyQualified($fqn));
    }

    private static function constantIn(SymbolSourceInterface $backend, string $fqn): ?ConstantInfo
    {
        return $backend->lookupConstant(new ConstantName(QualifiedName::fromFullyQualified($fqn)));
    }

    private static function functionIn(SymbolSourceInterface $backend, string $fqn): ?FunctionInfo
    {
        return $backend->lookupFunction(new FunctionName(QualifiedName::fromFullyQualified($fqn)));
    }
}
