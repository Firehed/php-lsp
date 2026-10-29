<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use PhpParser\Node;
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
     * @return iterable<string, array{Expr, Expr|Name, Node, ?MemberAccessKind}>
     */
    public static function accesses(): iterable
    {
        $object = new Expr\Variable('object');
        $name = new Identifier('member');
        $class = new Name('Foo');
        $parent = new Name('parent');
        $property = new VarLikeIdentifier('property');

        yield '->' => [new Expr\PropertyFetch($object, $name), $object, $name, MemberAccessKind::Instance];
        yield '?->' => [new Expr\NullsafePropertyFetch($object, $name), $object, $name, MemberAccessKind::Instance];
        yield '-> call' => [new Expr\MethodCall($object, $name), $object, $name, MemberAccessKind::Instance];
        yield '?-> call' => [new Expr\NullsafeMethodCall($object, $name), $object, $name, MemberAccessKind::Instance];
        yield ':: call' => [new Expr\StaticCall($class, $name), $class, $name, MemberAccessKind::Static];
        yield ':: property' => [
            new Expr\StaticPropertyFetch($class, $property),
            $class,
            $property,
            MemberAccessKind::Static,
        ];
        yield ':: constant' => [new Expr\ClassConstFetch($class, $name), $class, $name, MemberAccessKind::Static];
        yield 'parent::' => [new Expr\StaticCall($parent, $name), $parent, $name, MemberAccessKind::Parent];
        yield 'an expression before ::' => [new Expr\ClassConstFetch($object, $name), $object, $name, null];
    }

    #[DataProvider('accesses')]
    public function testReadsTheAccessWritten(Expr $node, Expr|Name $receiver, Node $name, ?MemberAccessKind $kind): void
    {
        $access = MemberAccess::of($node);

        self::assertNotNull($access, 'the node is a member access');
        self::assertSame($receiver, $access->receiver(), 'what the member is accessed on');
        self::assertSame($name, $access->name(), 'the member name as written');
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
