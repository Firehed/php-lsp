<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropertyName::class)]
class PropertyNameTest extends TestCase
{
    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $name = new PropertyName($owner, 'myProperty');
        self::assertSame('myProperty', $name->name);
        self::assertSame($owner, $name->owner);
    }
}
