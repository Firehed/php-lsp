<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;

/**
 * A component constructing TypeInterface implementations directly instead of through
 * TypeFactory, which RFC 1 §4.6 forbids.
 */
final class ConstructsTypeOutsideFactory
{
    public function makeClasslikeType(): ClasslikeType
    {
        return new ClasslikeType(ClasslikeName::fromFullyQualified('Foo\\Bar'));
    }

    public function makePrimitive(): PrimitiveType
    {
        return new PrimitiveType('string');
    }

    public function makeUnion(): UnionType
    {
        return new UnionType([new ClasslikeType(ClasslikeName::fromFullyQualified('Foo')), new PrimitiveType('null')]);
    }
}
