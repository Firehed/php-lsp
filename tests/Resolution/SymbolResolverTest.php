<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Closure;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\TreeNodeLocator;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Firehed\PhpLsp\Resolution\SymbolResolver;
use Firehed\PhpLsp\Resolution\TypeSource\TypeSourceInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(SymbolResolver::class)]
final class SymbolResolverTest extends TestCase
{
    use BuildsSymbolInfoTrait;
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{Closure(SymbolResolver, ClasslikeName): bool, ?ClassInfo, bool}>
     */
    public static function classLikePredicates(): iterable
    {
        $classLike = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isClassLike($n);
        yield 'unknown is not a class-like' => [$classLike, null, false];
        yield 'trait is a class-like' => [$classLike, self::classInfo('Subject', ClassKind::Trait_), true];

        $instantiable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isInstantiable($n);
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

        $typeHint = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isValidTypeHint($n);
        yield 'unknown is a type hint' => [$typeHint, null, true];
        yield 'enum is a type hint' => [$typeHint, self::classInfo('Subject', ClassKind::Enum_), true];
        yield 'trait is not a type hint' => [$typeHint, self::classInfo('Subject', ClassKind::Trait_), false];

        $extendable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isExtendableClass($n);
        yield 'unknown is not extendable' => [$extendable, null, false];
        yield 'abstract class is extendable' => [$extendable, self::classInfo('Subject', isAbstract: true), true];
        yield 'final class is not extendable' => [$extendable, self::classInfo('Subject', isFinal: true), false];
        yield 'interface is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Interface_), false];
        yield 'trait is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Trait_), false];
        yield 'enum is not extendable' => [$extendable, self::classInfo('Subject', ClassKind::Enum_), false];

        $attribute = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isAttribute($n);
        yield 'unknown is not an attribute' => [$attribute, null, false];
        yield 'attribute class is an attribute' => [$attribute, self::classInfo('Subject', isAttribute: true), true];
        yield 'plain class is not an attribute' => [$attribute, self::classInfo('Subject'), false];

        $throwable = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isThrowable($n);
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
     * @param Closure(SymbolResolver, ClasslikeName): bool $predicate
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
     * @return iterable<string, array{Closure(SymbolResolver, ClasslikeName): bool, string, bool}>
     */
    public static function memberResolverPredicates(): iterable
    {
        $interface = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isInterface($n);
        $trait = static fn (SymbolResolver $r, ClasslikeName $n): bool => $r->isTrait($n);
        yield 'interface' => [$interface, 'isInterface', true];
        yield 'not an interface' => [$interface, 'isInterface', false];
        yield 'trait' => [$trait, 'isTrait', true];
        yield 'not a trait' => [$trait, 'isTrait', false];
    }

    /**
     * @param Closure(SymbolResolver, ClasslikeName): bool $predicate
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

    public function testAClassNameIsLookedUpAsTheTreeResolvedIt(): void
    {
        $fixture = 'SignatureHelp.php';
        $content = $this->loadFixture($fixture);
        ['line' => $line, 'character' => $character] = $this->locateHoverMarker($content, 'class_instantiation');
        $user = self::classInfo('Fixtures\Domain\User');
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('lookupClassLike')->willReturnCallback(
            fn (ClasslikeName $name) => $name->qualifiedName->fullyQualifiedName() === 'Fixtures\Domain\User'
                ? $user
                : null,
        );

        $resolved = self::resolver(
            $symbols,
            syntax: new PhpParserSyntaxSource(new TreeAnnotator()),
            locator: new TreeNodeLocator(),
        )
            ->resolveAtPosition(new TextDocument('file:///' . $fixture, 'php', 1, $content), $line, $character);

        self::assertSame($user, $resolved, 'the imported name is looked up by its fully qualified form');
    }

    private static function resolver(
        SymbolSourceInterface $symbols,
        ?MemberResolverInterface $members = null,
        ?SyntaxSourceInterface $syntax = null,
        ?NodeLocatorInterface $locator = null,
    ): SymbolResolver {
        return new SymbolResolver(
            $syntax ?? self::createStub(SyntaxSourceInterface::class),
            $locator ?? self::createStub(NodeLocatorInterface::class),
            $symbols,
            $members ?? self::createStub(MemberResolverInterface::class),
            self::createStub(TypeSourceInterface::class),
        );
    }
}
