<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\Type;

/**
 * A consumer branching on a type's display representation rather than its
 * identity, which can diverge from the type's actual structure.
 */
final class ComparesFormat
{
    public function identicalToString(Type $type): bool
    {
        return $type->format() === 'string';
    }

    public function notIdenticalToString(Type $type): bool
    {
        return $type->format() !== 'string';
    }

    public function looselyEqual(Type $type): bool
    {
        return $type->format() == 'int';
    }

    public function looselyNotEqual(Type $type): bool
    {
        return $type->format() != 'int';
    }

    public function concatenationIsFine(Type $type): string
    {
        return 'Type: ' . $type->format();
    }

    public function assignmentIsFine(Type $type): string
    {
        $formatted = $type->format();
        return $formatted;
    }
}
