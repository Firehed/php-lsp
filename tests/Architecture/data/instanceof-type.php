<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A consumer deciding suitability by instanceof against concrete TypeInterface
 * implementations, which the rule forbids.
 */
final class InstanceofType
{
    public function isClassType(TypeInterface $type): bool
    {
        return $type instanceof ClasslikeType;
    }
}
