<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\MemberTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberTarget::class)]
final class MemberTargetTest extends TestCase
{
    public function testConstruction(): void
    {
        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ParentClass'));
        $from = ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ChildClass');

        $target = new MemberTarget($type, $from, $from);

        self::assertSame($type, $target->type, 'what the member is looked up on');
        self::assertSame($from, $target->from, 'where the access is written');
        self::assertSame($from, $target->static, 'what static names');
    }
}
