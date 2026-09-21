<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnumCaseName::class)]
class EnumCaseNameTest extends TestCase
{
    public function testConstruction(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Some\\Cls');
        $name = new EnumCaseName($owner, 'Active');
        self::assertSame('Active', $name->name);
        self::assertSame($owner, $name->owner);
    }
}
