<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccess::class)]
final class MemberAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{MemberAccessKind, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'parent:: passes on the class it is written in' => [MemberAccessKind::Parent, true];
        yield 'a static access is on the class static names' => [MemberAccessKind::Static, false];
        yield 'an instance is the class static names' => [MemberAccessKind::Instance, false];
    }

    /**
     * PHP manual, Late Static Bindings.
     */
    #[DataProvider('kinds')]
    public function testOnlyAParentAccessNamesADifferentStaticClass(MemberAccessKind $kind, bool $forwards): void
    {
        $on = new ClasslikeType(ClasslikeName::fromFullyQualified('App\\Base'));
        $from = ClasslikeName::fromFullyQualified('App\\Caller');

        $access = new MemberAccess($on, $from, $kind);

        self::assertSame([$on, $from, $kind], [$access->on, $access->from, $access->kind], 'what the access holds');
        self::assertSame(
            $forwards ? $from : null,
            $access->staticClass(),
            'the class static names, if not the one accessed',
        );
    }
}
