<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Closure;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\SymbolKind;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionSource::class)]
final class CompositeCompletionSourceTest extends TestCase
{
    use BuildsCompletionInputsTrait;
    use WiresCompletionSourceTrait;

    public function testVariableInsideACallOffersNamedArgumentsAndVariablesOnly(): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturn([self::symbol('var_dump', SymbolKind::Function_)]);

        self::assertSame(
            ['name:', '$variable'],
            self::labelsAfter('foo($va', $symbols, self::insideACall()),
            'a variable being typed in a call offers argument names and variables, not expressions',
        );
    }

    public function testAnExpressionInsideACallAlsoOffersKeywordsAndSymbols(): void
    {
        $labels = self::labelsAfter('foo(n', self::everySymbolKind(), self::insideACall());

        foreach (['name:', '$variable', 'null', 'Widget', 'Mixin', 'strlen', 'PHP_VERSION'] as $label) {
            self::assertContains(
                $label,
                $labels,
                "{$label}: argument names, variables, expression keywords, and every symbol kind are offered",
            );
        }
        self::assertNotContains('namespace', $labels, 'only expression keywords are offered');
    }

    public function testMemberAccessAnswersAloneEvenWithNoMembers(): void
    {
        $codeResolver = self::insideACall();
        $codeResolver->method('getMemberAccessContext')->willReturn(MemberAccessContext::forInstance(
            new ClasslikeType(ClasslikeName::fromFullyQualified('Widget')),
            Visibility::Public,
            '',
        ));
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());

        self::assertSame(
            [],
            self::labelsAfter('foo($x->', $symbols, $codeResolver),
            'member access owns the position: no argument names or variables from the enclosing call',
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function useStatements(): iterable
    {
        yield 'closure use' => ['$f = function () use ', ['$greeting']];
        yield 'import' => ['use Lib\\', ['Widget']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('useStatements')]
    public function testAUseOutsideAClassBodyIsAnImportUnlessItCapturesClosureVariables(
        string $code,
        array $expected,
    ): void {
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getNameContext')->willReturn(new NameContext(''));
        $codeResolver->method('isClassLike')->willReturn(true);
        $codeResolver->method('getVariablesInScope')->willReturn([
            new ResolvedVariable('greeting', new PrimitiveType('string')),
        ]);
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturnCallback(
            static fn (NamespaceName $namespace): NamespaceContents => $namespace->path === 'Lib'
                ? new NamespaceContents(symbols: [new CatalogSymbol('Lib\Widget', NameKind::ClassLike)])
                : new NamespaceContents(),
        );

        self::assertSame(
            $expected,
            self::labelsAfter($code, $symbols, $codeResolver),
            'a closure use offers only variables; an import offers only navigated class-likes',
        );
    }

    /**
     * The built-in type lists themselves are BuiltinTypeCandidatesTest's; each
     * case names a type that shows which position's list was asked for.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function typePositions(): iterable
    {
        $nonTypes = ['Mixin', 'strlen', 'PHP_VERSION'];
        yield 'return type' => ['function foo(): ', ['void', 'Widget'], [...$nonTypes, 'function']];
        yield 'parameter type' => ['function foo(', ['self', 'Widget'], [...$nonTypes, 'function', 'void']];
        yield 'property type' => ['class Foo { private ?', ['string', 'Widget'], [...$nonTypes, 'function', 'self']];
        yield 'after a visibility keyword' => ['class Foo { private ', ['function', 'string', 'Widget'], [
            ...$nonTypes,
            'self',
        ]];
    }

    /**
     * @param list<string> $offered
     * @param list<string> $withheld
     */
    #[DataProvider('typePositions')]
    public function testATypePositionOffersTypesOnly(string $code, array $offered, array $withheld): void
    {
        $labels = self::labelsWithEverySymbolKindAfter($code);

        foreach ($offered as $label) {
            self::assertContains($label, $labels, "{$label}: a built-in type or type-hintable class-like here");
        }
        foreach ($withheld as $label) {
            self::assertNotContains($label, $labels, "{$label}: not a type here");
        }
    }

    /**
     * Each class-like in the stub passes exactly one position's filter, so the
     * list shows which filter the position asked for.
     *
     * @return iterable<string, array{string, list<string>}>
     */
    public static function classPositions(): iterable
    {
        yield 'new' => ['$x = new ', ['Widget']];
        yield 'implements' => ['class Foo implements ', ['Contract']];
        yield 'class extends' => ['class Foo extends ', ['Base']];
        yield 'catch' => ['} catch (', ['Failure']];
        yield 'attribute' => ['#[', ['Marker']];
        yield 'instanceof' => ['$x instanceof ', ['Widget', 'Contract', 'Base', 'Failure', 'Marker']];
        yield 'trait use in a class body' => ["class Foo {\n    use ", ['Mixin']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('classPositions')]
    public function testAClassPositionOffersTheClassLikesItsFilterAccepts(string $code, array $expected): void
    {
        self::assertSame(
            $expected,
            self::labelsWithEverySymbolKindAfter($code),
            'only class-likes valid in the position: no functions, constants, or keywords',
        );
    }

    public function testAnExpressionOffersEverySymbolKind(): void
    {
        self::assertSame(
            ['Widget', 'Contract', 'Base', 'Failure', 'Marker', 'Mixin', 'PHP_VERSION', 'strlen'],
            self::labelsWithEverySymbolKindAfter('$x = Z'),
            'an expression offers class-likes, traits included, functions, and constants',
        );
    }

    /**
     * Each class position's predicate accepts exactly one of {@see everySymbolKind()}'s class-likes.
     *
     * @return list<string>
     */
    private static function labelsWithEverySymbolKindAfter(string $code): array
    {
        $only = static fn (string $accepted): Closure
            => static fn (ClasslikeName $name): bool => $name->equals(ClasslikeName::fromFullyQualified($accepted));
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getNameContext')->willReturn(new NameContext(''));
        $codeResolver->method('isValidTypeHint')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !$name->equals(ClasslikeName::fromFullyQualified('Mixin')),
        );
        $codeResolver->method('isInstantiable')->willReturnCallback($only('Widget'));
        $codeResolver->method('isInterface')->willReturnCallback($only('Contract'));
        $codeResolver->method('isExtendableClass')->willReturnCallback($only('Base'));
        $codeResolver->method('isThrowable')->willReturnCallback($only('Failure'));
        $codeResolver->method('isAttribute')->willReturnCallback($only('Marker'));
        $codeResolver->method('isTrait')->willReturnCallback($only('Mixin'));

        return self::labelsAfter($code, self::everySymbolKind(), $codeResolver);
    }

    /**
     * Every search answers with a function, a constant, a trait that is not a
     * valid type hint, and one class-like for each class position's predicate.
     */
    private static function everySymbolKind(): SymbolSourceInterface
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturnCallback(
            static fn (string $prefix, NameKind $kind): array => match ($kind) {
                NameKind::ClassLike => [
                    self::symbol('Widget', SymbolKind::Class_),
                    self::symbol('Contract', SymbolKind::Interface_),
                    self::symbol('Base', SymbolKind::Class_),
                    self::symbol('Failure', SymbolKind::Class_),
                    self::symbol('Marker', SymbolKind::Class_),
                    self::symbol('Mixin', SymbolKind::Trait_),
                ],
                NameKind::Function_ => [self::symbol('strlen', SymbolKind::Function_)],
                NameKind::Constant => [self::symbol('PHP_VERSION', SymbolKind::Constant)],
            },
        );

        return $symbols;
    }

    /**
     * A resolver that places the cursor in a call taking `$name`, with `$variable` in scope.
     */
    private static function insideACall(): CodeResolverInterface&Stub
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([
            new ParameterInfo('name', new PrimitiveType('string'), false, null, 0, false, false),
        ]);
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getCallContext')->willReturn(new CallContext($callable, 0, []));
        $codeResolver->method('getVariablesInScope')->willReturn([
            new ResolvedVariable('variable', new PrimitiveType('string')),
        ]);
        $codeResolver->method('getNameContext')->willReturn(new NameContext(''));

        return $codeResolver;
    }

    /**
     * @return list<string>
     */
    private static function labelsAfter(
        string $code,
        SymbolSourceInterface $symbols,
        CodeResolverInterface $codeResolver,
    ): array {
        $source = self::completionSourceFor($symbols, $codeResolver, self::capabilitiesProvider());

        return array_column($source->find(self::requestAfter($code)), 'label');
    }
}
