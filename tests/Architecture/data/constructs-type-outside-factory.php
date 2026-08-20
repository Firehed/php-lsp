<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\UnionType;

/**
 * A component constructing Type implementations directly instead of through
 * TypeFactory, which RFC 1 §4.6 forbids.
 */
final class ConstructsTypeOutsideFactory
{
    public function makeClassName(): ClassName
    {
        return new ClassName('Foo\\Bar');
    }

    public function makePrimitive(): PrimitiveType
    {
        return new PrimitiveType('string');
    }

    public function makeUnion(): UnionType
    {
        return new UnionType([new ClassName('Foo'), new PrimitiveType('null')]);
    }
}
