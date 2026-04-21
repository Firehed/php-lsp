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
        $name = new EnumCaseName('Active');
        self::assertSame('Active', $name->name);
    }
}
