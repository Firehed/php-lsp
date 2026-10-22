<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Closure;
use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFactory;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Domain\CatalogSymbol;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\NamespaceName;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\Position\CallAtPositionInterface;
use Firehed\PhpLsp\Resolution\Position\ScopeAtPositionInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolCapabilitiesInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionSource::class)]
final class CompositeCompletionSourceTest extends TestCase
{
    use BuildsCompletionInputsTrait;

    public function testVariableInsideACallOffersNamedArgumentsAndVariablesOnly(): void
    {
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturn([QualifiedName::fromFullyQualified('var_dump')]);

        self::assertSame(
            ['name:', '$variable'],
            self::labelsAfter(
                'foo($va',
                $symbols,
                self::createStub(SymbolCapabilitiesInterface::class),
                self::aCall(),
                self::aVariable(),
            ),
            'a variable being typed in a call offers argument names and variables, not expressions',
        );
    }

    public function testAnExpressionInsideACallAlsoOffersKeywordsAndSymbols(): void
    {
        $labels = self::labelsAfter(
            'foo(n',
            self::everySymbolKind(),
            self::createStub(SymbolCapabilitiesInterface::class),
            self::aCall(),
            self::aVariable(),
        );

        foreach (['name:', '$variable', 'null', 'Widget', 'Mixin', 'strlen', 'PHP_VERSION'] as $label) {
            self::assertContains(
                $label,
                $labels,
                "{$label}: argument names, variables, expression keywords, and every symbol kind are offered",
            );
        }
        self::assertNotContains('namespace', $labels, 'only expression keywords are offered');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function silentPositions(): iterable
    {
        yield 'a comment' => ['// Z'];
        yield 'after a finished statement' => ['$x = 1;'];
    }

    #[DataProvider('silentPositions')]
    public function testAPositionWithNothingToCompleteOffersNothing(string $code): void
    {
        self::assertSame([], self::labelsWithEverySymbolKindAfter($code), 'nothing is offered here');
    }

    public function testMemberAccessInACommentOffersNothing(): void
    {
        $owner = ClasslikeName::fromFullyQualified('Widget');
        $memberAccess = self::createStub(CodeResolverInterface::class);
        $memberAccess->method('getMemberAccessContext')->willReturn(
            MemberAccessContext::forInstance(new ClasslikeType($owner), Visibility::Public, ''),
        );
        $symbolCapabilities = self::createStub(SymbolCapabilitiesInterface::class);
        $symbolCapabilities->method('getAccessibleMembers')->willReturn([new MethodInfo(
            name: new MethodName($owner, 'save'),
            visibility: Visibility::Public,
            isStatic: false,
            isAbstract: false,
            isFinal: false,
            parameters: [],
            returnType: null,
            docblock: null,
            file: null,
            line: null,
        )]);

        self::assertSame(
            [],
            self::labelsAfter('// $x->', self::everySymbolKind(), $symbolCapabilities, memberAccess: $memberAccess),
            'a comment is checked before member access, so its members are not offered',
        );
    }

    public function testAnInterpolatedStringOffersOnlyVariables(): void
    {
        self::assertSame(
            ['$variable'],
            self::labelsAfter(
                'foo("Z',
                self::everySymbolKind(),
                self::createStub(SymbolCapabilitiesInterface::class),
                self::aCall(),
                self::aVariable(),
            ),
            'only a variable can be interpolated, even inside a call',
        );
    }

    public function testMemberAccessAnswersAloneEvenWithNoMembers(): void
    {
        $memberAccess = self::createStub(CodeResolverInterface::class);
        $memberAccess->method('getMemberAccessContext')->willReturn(MemberAccessContext::forInstance(
            new ClasslikeType(ClasslikeName::fromFullyQualified('Widget')),
            Visibility::Public,
            '',
        ));
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());

        self::assertSame(
            [],
            self::labelsAfter(
                'foo($x->',
                $symbols,
                self::createStub(SymbolCapabilitiesInterface::class),
                self::aCall(),
                memberAccess: $memberAccess,
            ),
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
        $symbolCapabilities = self::createStub(SymbolCapabilitiesInterface::class);
        $symbolCapabilities->method('isClassLike')->willReturn(true);
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturnCallback(
            static fn (NamespaceName $namespace): NamespaceContents => $namespace->path === 'Lib'
                ? new NamespaceContents(symbols: [new CatalogSymbol('Lib\Widget', NameKind::ClassLike)])
                : new NamespaceContents(),
        );

        self::assertSame(
            $expected,
            self::labelsAfter(
                $code,
                $symbols,
                $symbolCapabilities,
                variables: [new ResolvedVariable('greeting', new PrimitiveType('string'))],
            ),
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
        $symbolCapabilities = self::createStub(SymbolCapabilitiesInterface::class);
        $symbolCapabilities->method('isValidTypeHint')->willReturnCallback(
            static fn (ClasslikeName $name): bool => !$name->equals(ClasslikeName::fromFullyQualified('Mixin')),
        );
        $symbolCapabilities->method('isInstantiable')->willReturnCallback($only('Widget'));
        $symbolCapabilities->method('isInterface')->willReturnCallback($only('Contract'));
        $symbolCapabilities->method('isExtendableClass')->willReturnCallback($only('Base'));
        $symbolCapabilities->method('isThrowable')->willReturnCallback($only('Failure'));
        $symbolCapabilities->method('isAttribute')->willReturnCallback($only('Marker'));
        $symbolCapabilities->method('isTrait')->willReturnCallback($only('Mixin'));

        return self::labelsAfter($code, self::everySymbolKind(), $symbolCapabilities);
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
            static fn (string $prefix, NameKind $kind): array => array_map(
                QualifiedName::fromFullyQualified(...),
                match ($kind) {
                    NameKind::ClassLike => ['Widget', 'Contract', 'Base', 'Failure', 'Marker', 'Mixin'],
                    NameKind::Function_ => ['strlen'],
                    NameKind::Constant => ['PHP_VERSION'],
                },
            ),
        );

        return $symbols;
    }

    /**
     * A call taking `$name`.
     */
    private static function aCall(): CallContext
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([
            new ParameterInfo('name', new PrimitiveType('string'), null, 0, false, false),
        ]);
        return new CallContext($callable, 0, []);
    }

    /**
     * @return list<ResolvedVariable>
     */
    private static function aVariable(): array
    {
        return [new ResolvedVariable('variable', new PrimitiveType('string'))];
    }

    /**
     * @param list<ResolvedVariable> $variables
     * @return list<string>
     */
    private static function labelsAfter(
        string $code,
        SymbolSourceInterface $symbols,
        SymbolCapabilitiesInterface $symbolCapabilities,
        ?CallContext $call = null,
        array $variables = [],
        ?CodeResolverInterface $memberAccess = null,
    ): array {
        $capabilities = self::capabilitiesProvider();
        $itemFactory = new CompletionItemFactory(new ResolvedSymbolPresenter());
        $calls = self::createStub(CallAtPositionInterface::class);
        $calls->method('callAt')->willReturn($call);
        $scope = self::createStub(ScopeAtPositionInterface::class);
        $scope->method('variablesAt')->willReturn($variables);
        $scope->method('nameContextAt')->willReturn(new NameContext(''));
        $source = new CompositeCompletionSource(
            $calls,
            new SymbolCandidates($symbols, $symbolCapabilities, $scope, $capabilities, $itemFactory),
            new KeywordCandidates($itemFactory),
            new VariableCandidates($scope, $itemFactory),
            new MemberCandidates(
                $memberAccess ?? self::createStub(CodeResolverInterface::class),
                $symbolCapabilities,
                $capabilities,
                $itemFactory,
            ),
            new NamedArgumentCandidates($calls, $itemFactory),
            new BuiltinTypeCandidates($itemFactory),
        );

        return array_column($source->find(self::requestAfter($code)), 'label');
    }
}
