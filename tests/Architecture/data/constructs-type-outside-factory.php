<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;

/**
 * A component constructing TypeInterface implementations directly instead of through
 * TypeFactory, which RFC 1 §4.6 forbids.
 */
final class ConstructsTypeOutsideFactory
{
    public function makeClasslikeName(): ClasslikeName
    {
        return new ClasslikeName('Foo\\Bar');
    }

    public function makePrimitive(): PrimitiveType
    {
        return new PrimitiveType('string');
    }

    public function makeUnion(): UnionType
    {
        return new UnionType([new ClasslikeName('Foo'), new PrimitiveType('null')]);
    }
}
