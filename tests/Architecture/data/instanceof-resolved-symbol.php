<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\ResolvedSymbolInterface;

/**
 * A consumer deciding suitability by instanceof against concrete ResolvedSymbolInterface
 * implementations, which RFC 1 §4.5 forbids.
 */
final class InstanceofResolvedSymbol
{
    public function isSuitableForParentAccess(ResolvedSymbolInterface $symbol): bool
    {
        return $symbol instanceof MethodInfo;
    }

    public function isSuitableViaVariable(ResolvedSymbolInterface $symbol): bool
    {
        $wanted = MethodInfo::class;

        return $symbol instanceof $wanted;
    }

    public function getKind(ResolvedSymbolInterface $symbol): string
    {
        return match (true) {
            $symbol instanceof MethodInfo => 'method',
            $symbol instanceof PropertyInfo => 'property',
            default => 'unknown',
        };
    }
}
