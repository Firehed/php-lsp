<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Utility;

use Firehed\PhpLsp\Tests\Fixtures\SampleTrait;
use Firehed\PhpLsp\Utility\ReflectionHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ReflectionHelper::class)]
class ReflectionHelperTest extends TestCase
{
    public function testGetClassReturnsReflectionForExistingClass(): void
    {
        $result = ReflectionHelper::getClass(\stdClass::class);

        self::assertInstanceOf(ReflectionClass::class, $result);
        self::assertSame('stdClass', $result->getName());
    }

    public function testGetClassReturnsReflectionForExistingInterface(): void
    {
        $result = ReflectionHelper::getClass(\Iterator::class);

        self::assertInstanceOf(ReflectionClass::class, $result);
        self::assertSame('Iterator', $result->getName());
        self::assertTrue($result->isInterface());
    }

    public function testGetClassReturnsReflectionForExistingTrait(): void
    {
        $result = ReflectionHelper::getClass(SampleTrait::class);

        self::assertInstanceOf(ReflectionClass::class, $result);
        self::assertSame(SampleTrait::class, $result->getName());
        self::assertTrue($result->isTrait());
    }

    public function testGetClassReturnsNullForNonExistentClass(): void
    {
        $result = ReflectionHelper::getClass('NonExistent\\Class\\Name');

        self::assertNull($result);
    }
}
