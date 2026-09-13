<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A consumer deciding suitability by instanceof against concrete TypeInterface
 * implementations, which RFC 1 §4.5 forbids.
 */
final class InstanceofType
{
    public function isClassType(TypeInterface $type): bool
    {
        return $type instanceof ClassName;
    }
}
