<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Domain;

use Firehed\PhpLsp\Domain\LateBindingKeyword;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LateBindingKeyword::class)]
final class LateBindingKeywordTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?LateBindingKeyword}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function names(): iterable
    {
        yield 'self lower' => ['self', LateBindingKeyword::Self];
        yield 'Self mixed' => ['Self', LateBindingKeyword::Self];
        yield 'SELF upper' => ['SELF', LateBindingKeyword::Self];
        yield 'static' => ['static', LateBindingKeyword::Static];
        yield 'Static' => ['Static', LateBindingKeyword::Static];
        yield 'parent' => ['parent', LateBindingKeyword::Parent];
        yield 'PARENT' => ['PARENT', LateBindingKeyword::Parent];
        yield 'other' => ['User', null];
        yield 'empty' => ['', null];
        yield 'ns-qualified self' => ['App\\self', null];
    }

    #[DataProvider('names')]
    public function testTryFromNameIsCaseInsensitive(string $name, ?LateBindingKeyword $expected): void
    {
        self::assertSame(
            $expected,
            LateBindingKeyword::tryFromName($name),
            'the three keywords resolve regardless of source case; anything else is not a keyword',
        );
    }

    public function testResolveInReturnsNullWithoutEnclosingClassLike(): void
    {
        self::assertNull(
            LateBindingKeyword::Self->resolveIn(null),
            'no enclosing class means no self',
        );
        self::assertNull(
            LateBindingKeyword::Static->resolveIn(null),
            'no enclosing class means no static',
        );
        self::assertNull(
            LateBindingKeyword::Parent->resolveIn(null),
            'no enclosing class means no parent',
        );
    }

    public function testResolveInSelfReturnsEnclosingClassName(): void
    {
        $class = new Stmt\Class_('Foo');
        $class->namespacedName = new Name('App\\Foo');

        self::assertSame('App\\Foo', LateBindingKeyword::Self->resolveIn($class));
        self::assertSame('App\\Foo', LateBindingKeyword::Static->resolveIn($class));
    }

    public function testResolveInSelfFallsBackToShortNameWithoutNamespaceName(): void
    {
        $class = new Stmt\Class_('Foo');

        self::assertSame('Foo', LateBindingKeyword::Self->resolveIn($class));
    }

    public function testResolveInSelfReturnsNullForAnonymousClass(): void
    {
        $class = new Stmt\Class_(null);

        self::assertNull(LateBindingKeyword::Self->resolveIn($class));
    }

    public function testResolveInParentReadsExtendsResolvedName(): void
    {
        $extends = new Name('Base');
        $extends->setAttribute('resolvedName', new Name('App\\Base'));
        $class = new Stmt\Class_('Foo', ['extends' => $extends]);

        self::assertSame('App\\Base', LateBindingKeyword::Parent->resolveIn($class));
    }

    public function testResolveInParentFallsBackToRawExtendsName(): void
    {
        $class = new Stmt\Class_('Foo', ['extends' => new Name('App\\Base')]);

        self::assertSame('App\\Base', LateBindingKeyword::Parent->resolveIn($class));
    }

    public function testResolveInParentReturnsNullWithoutExtends(): void
    {
        $class = new Stmt\Class_('Foo');

        self::assertNull(
            LateBindingKeyword::Parent->resolveIn($class),
            'a class with no extends clause has no parent',
        );
    }

    public function testResolveInParentReturnsNullForInterface(): void
    {
        $interface = new Stmt\Interface_('Bar');
        $interface->namespacedName = new Name('App\\Bar');

        self::assertNull(
            LateBindingKeyword::Parent->resolveIn($interface),
            'interfaces cannot use parent for class resolution',
        );
    }

    public function testResolveInParentReturnsNullForTrait(): void
    {
        $trait = new Stmt\Trait_('T');
        $trait->namespacedName = new Name('App\\T');

        self::assertNull(LateBindingKeyword::Parent->resolveIn($trait));
    }

    public function testResolveInParentReturnsNullForEnum(): void
    {
        $enum = new Stmt\Enum_('E');
        $enum->namespacedName = new Name('App\\E');

        self::assertNull(LateBindingKeyword::Parent->resolveIn($enum));
    }
}
