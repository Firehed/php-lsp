<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccess::class)]
final class MemberAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{Expr, Expr|Name, Identifier|Expr, bool, ?MemberAccessKind}>
     */
    public static function accesses(): iterable
    {
        $object = new Expr\Variable('object');
        $name = new Identifier('member');
        $class = new Name('Foo');
        $parent = new Name('parent');
        $property = new VarLikeIdentifier('property');
        $instance = MemberAccessKind::Instance;
        $static = MemberAccessKind::Static;

        yield '->' => [new Expr\PropertyFetch($object, $name), $object, $name, false, $instance];
        yield '?->' => [new Expr\NullsafePropertyFetch($object, $name), $object, $name, false, $instance];
        yield '-> call' => [new Expr\MethodCall($object, $name), $object, $name, false, $instance];
        yield '?-> call' => [new Expr\NullsafeMethodCall($object, $name), $object, $name, false, $instance];
        yield ':: call' => [new Expr\StaticCall($class, $name), $class, $name, true, $static];
        yield ':: property' => [new Expr\StaticPropertyFetch($class, $property), $class, $property, true, $static];
        yield ':: constant' => [new Expr\ClassConstFetch($class, $name), $class, $name, true, $static];
        yield 'parent::' => [
            new Expr\StaticCall($parent, $name),
            $parent,
            $name,
            true,
            MemberAccessKind::Parent,
        ];
        yield 'an expression before ::' => [new Expr\ClassConstFetch($object, $name), $object, $name, true, null];
    }

    #[DataProvider('accesses')]
    public function testReadsTheAccessWritten(
        Expr $node,
        Expr|Name $receiver,
        Identifier|Expr $name,
        bool $doubleColon,
        ?MemberAccessKind $kind,
    ): void {
        $access = MemberAccess::of($node);

        self::assertNotNull($access, 'the node is a member access');
        self::assertSame($node, $access->node, 'the access node itself');
        self::assertSame($receiver, $access->receiver, 'what the member is accessed on');
        self::assertSame($name, $access->name, 'the member name as written');
        self::assertSame($doubleColon, $access->doubleColon, 'whether it is written with ::');
        self::assertSame($kind, $access->kind(), 'how the access is written');
    }

    public function testParentIsMatchedWhateverItsCase(): void
    {
        $access = MemberAccess::of(new Expr\StaticCall(new Name('PARENT'), 'make'));

        self::assertSame(MemberAccessKind::Parent, $access?->kind(), 'PHP keywords are case-insensitive');
    }

    public function testOtherNodesAreNotAccesses(): void
    {
        self::assertNull(MemberAccess::of(new Expr\FuncCall(new Name('strlen'))), 'a function call');
        self::assertNull(MemberAccess::of(new Expr\New_(new Name('Foo'))), 'a constructor runs no member access');
    }
}
