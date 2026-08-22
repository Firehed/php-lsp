<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Resolution\ResolvedMethod;
use Firehed\PhpLsp\Resolution\ResolvedProperty;
use Firehed\PhpLsp\Resolution\ResolvedSymbol;

/**
 * A consumer deciding suitability by instanceof against concrete ResolvedSymbol
 * implementations, which RFC 1 §4.5 forbids.
 */
final class InstanceofResolvedSymbol
{
    public function isSuitableForParentAccess(ResolvedSymbol $symbol): bool
    {
        return $symbol instanceof ResolvedMethod;
    }

    public function isSuitableViaVariable(ResolvedSymbol $symbol): bool
    {
        $wanted = ResolvedMethod::class;

        return $symbol instanceof $wanted;
    }

    public function getKind(ResolvedSymbol $symbol): string
    {
        return match (true) {
            $symbol instanceof ResolvedMethod => 'method',
            $symbol instanceof ResolvedProperty => 'property',
            default => 'unknown',
        };
    }
}
