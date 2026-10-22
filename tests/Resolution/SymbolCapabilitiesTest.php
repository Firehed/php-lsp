<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Closure;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\UnionType;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\SymbolCapabilities;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(SymbolCapabilities::class)]
final class SymbolCapabilitiesTest extends TestCase
{
    use BuildsSymbolInfoTrait;

    /**
     * @return iterable<string, array{Closure(SymbolCapabilities, ClasslikeName): bool, ?ClassInfo, bool}>
     */
    public static function classLikePredicates(): iterable
    {
        $classLike = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isClassLike($n);
        yield 'unknown is not a class-like' => [$classLike, null, false];
        yield 'trait is a class-like' => [$classLike, self::classInfo('Subject', ClassKind::Trait_), true];

        $instantiable = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isInstantiable($n);
        yield 'unknown is instantiable' => [$instantiable, null, true];
        yield 'concrete class is instantiable' => [$instantiable, self::classInfo('Subject'), true];
        yield 'abstract class is not instantiable' => [
            $instantiable,
            self::classInfo('Subject', isAbstract: true),
            false,
        ];
        yield 'interface is not instantiable' => [
            $instantiable,
            self::classInfo('Subject', ClassKind::Interface_),
            false,
        ];
        yield 'enum is not instantiable' => [$instantiable, self::classInfo('Subject', ClassKind::Enum_), false];

        $typeHint = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isValidTypeHint($n);
        yield 'unknown is a type hint' => [$typeHint, null, true];
        yield 'enum is a type hint' => [$typeHint, self::classInfo('Subject', ClassKind::Enum_), true];
        yield 'trait is not a type hint' => [$typeHint, self::classInfo('Subject', ClassKind::Trait_), false];

        $extendable = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isExtendableClass($n);
        yield 'unknown is not extendable' => [$extendable, null, false];
        yield 'abstract class is extendable' => [$extendable, self::classInfo('Subject', isAbstract: true), true];
        yield 'final class is not extendable' => [$extendable, self::classInfo('Subject', isFinal: true), false];
        yield 'interface is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Interface_), false];
        yield 'trait is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Trait_), false];
        yield 'enum is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Enum_), false];

        $attribute = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isAttribute($n);
        yield 'unknown is not an attribute' => [$attribute, null, false];
        yield 'attribute class is an attribute' => [$attribute, self::classInfo('Subject', isAttribute: true), true];
        yield 'plain class is not an attribute' => [$attribute, self::classInfo('Subject'), false];

        $throwable = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isThrowable($n);
        yield 'unknown is not throwable' => [$throwable, null, false];
        yield 'Throwable itself is throwable' => [
            $throwable,
            self::classInfo(Throwable::class, ClassKind::Interface_),
            true,
        ];
        yield 'class outside the Throwable hierarchy is not throwable' => [
            $throwable,
            self::classInfo('Subject'),
            false,
        ];
    }

    /**
     * @param Closure(SymbolCapabilities, ClasslikeName): bool $predicate
     */
    #[DataProvider('classLikePredicates')]
    public function testPredicateReadsTheDeclaration(Closure $predicate, ?ClassInfo $declared, bool $expected): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturn($declared);
        $name = $declared->name ?? ClasslikeName::fromFullyQualified('Unknown');

        self::assertSame(
            $expected,
            $predicate(self::resolver($symbols), $name),
            'the predicate follows the declaration, and an unknown name follows the position\'s default',
        );
    }

    public function testDescendantOfThrowableIsThrowable(): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturn(self::classInfo('Subject'));
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('isSubclassOf')->willReturnCallback(
            static fn (ClasslikeName $class, ClasslikeName $parent): bool
                => $parent->equals(ClasslikeName::fromFullyQualified(Throwable::class)),
        );

        self::assertTrue(
            self::resolver($symbols, $members)->isThrowable(ClasslikeName::fromFullyQualified('Subject')),
            'anything extending or implementing Throwable can be caught',
        );
    }

    /**
     * @return iterable<string, array{Closure(SymbolCapabilities, ClasslikeName): bool, string, bool}>
     */
    public static function memberResolverPredicates(): iterable
    {
        $interface = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isInterface($n);
        $trait = static fn (SymbolCapabilities $r, ClasslikeName $n): bool => $r->isTrait($n);
        yield 'interface' => [$interface, 'isInterface', true];
        yield 'not an interface' => [$interface, 'isInterface', false];
        yield 'trait' => [$trait, 'isTrait', true];
        yield 'not a trait' => [$trait, 'isTrait', false];
    }

    /**
     * @param Closure(SymbolCapabilities, ClasslikeName): bool $predicate
     */
    #[DataProvider('memberResolverPredicates')]
    public function testPredicateAsksTheMemberResolver(Closure $predicate, string $method, bool $answer): void
    {
        $members = self::createStub(MemberResolverInterface::class);
        $members->method($method)->willReturn($answer);

        self::assertSame(
            $answer,
            $predicate(
                self::resolver(self::createStub(SymbolSourceInterface::class), $members),
                ClasslikeName::fromFullyQualified('Subject'),
            ),
            'the type graph answers what kind a class-like is',
        );
    }

    /**
     * @return iterable<string, array{MemberFilter, list<MemberKind>}>
     */
    public static function memberFilters(): iterable
    {
        $instance = [MemberKind::Method, MemberKind::Property];
        $every = [...$instance, MemberKind::Constant, MemberKind::EnumCase];
        yield 'instance access' => [MemberFilter::Instance, $instance];
        yield 'static access' => [MemberFilter::Static, $every];
        yield 'either' => [MemberFilter::All, $every];
    }

    /**
     * @param list<MemberKind> $kinds
     */
    #[DataProvider('memberFilters')]
    public function testAccessibleMembersAreEachClassesMembersOfTheKindsTheAccessReaches(
        MemberFilter $filter,
        array $kinds,
    ): void {
        $classes = ['First', 'Second'];
        $byClassAndKind = [];
        foreach ($classes as $class) {
            foreach (MemberKind::cases() as $kind) {
                $byClassAndKind[$class][$kind->name] = self::createStub(ResolvedMemberInterface::class);
            }
        }
        $members = self::createStub(MemberResolverInterface::class);
        $members->method('getMembersOfKind')->willReturnCallback(
            static fn (ClasslikeName $class, MemberKind $kind, Visibility $visibility, MemberFilter $passed): array
                => $visibility === Visibility::Protected && $passed === $filter
                    ? [$byClassAndKind[$class->qualifiedName->fullyQualifiedName()][$kind->name]]
                    : [],
        );
        $expected = [];
        foreach ($classes as $class) {
            foreach ($kinds as $kind) {
                $expected[] = $byClassAndKind[$class][$kind->name];
            }
        }

        self::assertSame(
            $expected,
            self::resolver(self::createStub(SymbolSourceInterface::class), $members)->getAccessibleMembers(
                new UnionType(array_map(
                    static fn (string $class): ClasslikeType => new ClasslikeType(self::className($class)),
                    $classes,
                )),
                Visibility::Protected,
                $filter,
            ),
            'every class the type may be, each with the kinds of member its access reaches, at the given visibility',
        );
    }

    private static function resolver(
        SymbolSourceInterface $symbols,
        ?MemberResolverInterface $members = null,
    ): SymbolCapabilities {
        return new SymbolCapabilities($symbols, $members ?? self::createStub(MemberResolverInterface::class));
    }
}
