<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberTarget::class)]
final class MemberTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{MemberAccessKind, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'an instance access' => [MemberAccessKind::Instance, false];
        yield 'a static access' => [MemberAccessKind::Static, false];
        yield 'a parent:: access' => [MemberAccessKind::Parent, true];
    }

    /**
     * Only `parent::` forwards late static binding (PHP manual, Late Static
     * Bindings): `static` is then the class the access is written in.
     */
    #[DataProvider('kinds')]
    public function testOnlyAParentAccessNamesStatic(MemberAccessKind $kind, bool $forwards): void
    {
        $type = new ClasslikeType(ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ParentClass'));
        $from = ClasslikeName::fromFullyQualified('Fixtures\Inheritance\ChildClass');

        $target = new MemberTarget($type, $from, $kind);

        self::assertSame($type, $target->type, 'what the member is looked up on');
        self::assertSame($from, $target->from, 'where the access is written');
        self::assertSame($kind, $target->kind, 'how the access is written');
        self::assertSame($forwards ? $from : null, $target->static(), 'what static names');
    }
}
