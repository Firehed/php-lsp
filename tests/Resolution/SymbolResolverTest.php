<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Closure;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(SymbolResolver, ClasslikeName): bool, ?ClassInfo, bool}>
     */
    public static function classLikePredicates(): iterable
    {
        $instantiable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isInstantiable($n);
        yield 'unknown is instantiable' => [$instantiable, null, true];
        yield 'concrete class is instantiable' => [$instantiable, self::classInfo(ClassKind::Class_), true];
        yield 'abstract class is not instantiable' => [
            $instantiable,
            self::classInfo(ClassKind::Class_, isAbstract: true),
            false,
        ];
        yield 'interface is not instantiable' => [$instantiable, self::classInfo(ClassKind::Interface_), false];

        $typeHint = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isValidTypeHint($n);
        yield 'unknown is a type hint' => [$typeHint, null, true];
        yield 'enum is a type hint' => [$typeHint, self::classInfo(ClassKind::Enum_), true];
        yield 'trait is not a type hint' => [$typeHint, self::classInfo(ClassKind::Trait_), false];

        $extendable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isExtendableClass($n);
        yield 'unknown is not extendable' => [$extendable, null, false];
        yield 'abstract class is extendable' => [
            $extendable,
            self::classInfo(ClassKind::Class_, isAbstract: true),
            true,
        ];
        yield 'final class is not extendable' => [
            $extendable,
            self::classInfo(ClassKind::Class_, isFinal: true),
            false,
        ];
        yield 'interface is not extendable' => [$extendable, self::classInfo(ClassKind::Interface_), false];

        $attribute = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isAttribute($n);
        yield 'unknown is not an attribute' => [$attribute, null, false];
        yield 'attribute class is an attribute' => [
            $attribute,
            self::classInfo(ClassKind::Class_, isAttribute: true),
            true,
        ];
        yield 'plain class is not an attribute' => [$attribute, self::classInfo(ClassKind::Class_), false];

        $throwable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isThrowable($n);
        yield 'unknown is not throwable' => [$throwable, null, false];
        yield 'Throwable itself is throwable' => [
            $throwable,
            self::classInfo(ClassKind::Interface_, name: Throwable::class),
            true,
        ];
        yield 'class outside the Throwable hierarchy is not throwable' => [
            $throwable,
            self::classInfo(ClassKind::Class_),
            false,
        ];
    }

    /**
     * @param Closure(SymbolResolver, ClasslikeName): bool $predicate
     */
    #[DataProvider('classLikePredicates')]
    public function testPredicateReadsTheDeclaration(Closure $predicate, ?ClassInfo $declared, bool $expected): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturn($declared);

        self::assertSame(
            $expected,
            $predicate(self::resolver($symbols), ClasslikeName::fromFullyQualified('Subject')),
            'the predicate follows the declaration, and an unknown name follows the position\'s default',
        );
    }

    public function testDescendantOfThrowableIsThrowable(): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturn(self::classInfo(ClassKind::Class_));
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
     * @return iterable<string, array{Closure(SymbolResolver, ClasslikeName): bool, string}>
     */
    public static function memberResolverPredicates(): iterable
    {
        yield 'interface' => [
            static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isInterface($n),
            'isInterface',
        ];
        yield 'trait' => [
            static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isTrait($n),
            'isTrait',
        ];
    }

    /**
     * @param Closure(SymbolResolver, ClasslikeName): bool $predicate
     */
    #[DataProvider('memberResolverPredicates')]
    public function testPredicateAsksTheMemberResolver(Closure $predicate, string $method): void
    {
        $members = self::createStub(MemberResolverInterface::class);
        $members->method($method)->willReturn(true);

        self::assertTrue(
            $predicate(
                self::resolver(self::createStub(SymbolSourceInterface::class), $members),
                ClasslikeName::fromFullyQualified('Subject'),
            ),
            'the type graph answers what kind a class-like is',
        );
    }

    private static function resolver(
        SymbolSourceInterface $symbols,
        ?MemberResolverInterface $members = null,
    ): SymbolResolver {
        return new SymbolResolver(
            self::createStub(SyntaxSourceInterface::class),
            $symbols,
            $members ?? self::createStub(MemberResolverInterface::class),
            self::createStub(TypeSourceInterface::class),
        );
    }

    private static function classInfo(
        ClassKind $kind,
        bool $isAbstract = false,
        bool $isFinal = false,
        bool $isAttribute = false,
        string $name = 'Subject',
    ): ClassInfo {
        return new ClassInfo(
            name: ClasslikeName::fromFullyQualified($name),
            kind: $kind,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            isReadonly: false,
            isAttribute: $isAttribute,
            parent: null,
            interfaces: [],
            traits: [],
            methods: [],
            properties: [],
            constants: [],
            enumCases: [],
            docblock: null,
            file: null,
            line: null,
        );
    }
}
