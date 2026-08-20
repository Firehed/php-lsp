<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\Type;

/**
 * A consumer deciding suitability by instanceof against concrete Type
 * implementations, which RFC 1 §4.5 forbids.
 */
final class InstanceofType
{
    public function isClassType(Type $type): bool
    {
        return $type instanceof ClassName;
    }
}
