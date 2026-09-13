<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * A consumer branching on a type's display representation rather than its
 * identity, which can diverge from the type's actual structure.
 */
final class ComparesFormat
{
    public function identicalToString(TypeInterface $type): bool
    {
        return $type->format() === 'string';
    }

    public function notIdenticalToString(TypeInterface $type): bool
    {
        return $type->format() !== 'string';
    }

    public function looselyEqual(TypeInterface $type): bool
    {
        return $type->format() == 'int';
    }

    public function looselyNotEqual(TypeInterface $type): bool
    {
        return $type->format() != 'int';
    }

    public function concatenationIsFine(TypeInterface $type): string
    {
        return 'Type: ' . $type->format();
    }

    public function assignmentIsFine(TypeInterface $type): string
    {
        $formatted = $type->format();
        return $formatted;
    }
}
