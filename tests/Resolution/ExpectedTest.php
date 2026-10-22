<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\Expected;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Expected::class)]
final class ExpectedTest extends TestCase
{
    public function testNothingIsAdmittedByDefault(): void
    {
        $expected = new Expected('fo');

        self::assertSame('fo', $expected->prefix, 'what is written up to the position');
        self::assertNull($expected->members, 'no members');
        self::assertNull($expected->argumentNames, 'no argument names');
        self::assertFalse($expected->variables, 'no variables');
        self::assertNull($expected->keywords, 'no keywords');
        self::assertNull($expected->builtinTypes, 'no built-in types');
        self::assertNull($expected->names, 'no names');
    }
}
