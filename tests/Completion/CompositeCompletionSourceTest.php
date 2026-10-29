<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\BuiltinTypeCandidates;
use Firehed\PhpLsp\Completion\CompletionItemFormatter;
use Firehed\PhpLsp\Completion\CompositeCompletionSource;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Completion\SymbolCandidates;
use Firehed\PhpLsp\Completion\VariableCandidates;
use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\NamespaceContents;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\ResolvedCallableInterface;
use Firehed\PhpLsp\Domain\TypeHintContext;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\Expected;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\MemberTarget;
use Firehed\PhpLsp\Resolution\NameContext;
use Firehed\PhpLsp\Resolution\NameQuery;
use Firehed\PhpLsp\Resolution\Position\ExpectedAtPositionInterface;
use Firehed\PhpLsp\Resolution\Position\ScopeAtPositionInterface;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\ResolvedVariable;
use Firehed\PhpLsp\Resolution\SymbolCapabilitiesInterface;
use Firehed\PhpLsp\Tests\BuildsSymbolInfoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeCompletionSource::class)]
final class CompositeCompletionSourceTest extends TestCase
{
    use BuildsCompletionInputsTrait;
    use BuildsSymbolInfoTrait;

    public function testEachThingThePositionAdmitsIsOfferedByItsSourceInOneList(): void
    {
        $callable = self::createStub(ResolvedCallableInterface::class);
        $callable->method('getParameters')->willReturn([self::parameterInfo('name', PrimitiveType::String, 0)]);

        $items = self::source(new Expected(
            'n',
            members: new MemberAccessContext(
                new MemberTarget(new ClasslikeType(self::owner()), null, MemberAccessKind::Instance),
                '',
            ),
            argumentNames: new CallContext($callable, 0, []),
            variables: true,
            keywords: KeywordGroup::Expression,
            builtinTypes: TypeHintContext::Property,
            names: new NameQuery([NameKind::ClassLike], ClassCandidateFilter::Any, new NameContext('')),
        ))->find(self::requestAfter('n'));

        self::assertSame(
            ['save', 'name:', '$variable', 'new', 'null', 'Node'],
            array_column($items, 'label'),
            'members, argument names, variables, keywords, built-in types, then names; each label once',
        );
        self::assertSame(
            ['"new', '"null'],
            array_column(array_slice($items, 3, 2), 'sortText'),
            'a keyword in an argument ranks below a symbol with the same prefix',
        );
    }

    public function testAPositionThatAdmitsNothingOffersNothing(): void
    {
        self::assertSame([], self::source(new Expected(''))->find(self::requestAfter('')), 'no source is asked');
    }

    public function testAStatementKeywordKeepsItsRank(): void
    {
        $items = self::source(new Expected('fore', keywords: KeywordGroup::All))->find(self::requestAfter('fore'));

        self::assertSame(['foreach'], array_column($items, 'label'), 'the keyword is offered');
        self::assertArrayNotHasKey('sortText', $items[0], 'nothing competes with a statement keyword for its rank');
    }

    /**
     * Each source answers with one candidate of its own.
     */
    private static function source(Expected $expected): CompositeCompletionSource
    {
        $at = self::createStub(ExpectedAtPositionInterface::class);
        $at->method('expectedAt')->willReturn($expected);
        $capabilities = self::createStub(SymbolCapabilitiesInterface::class);
        $capabilities->method('getAccessibleMembers')->willReturn([self::methodInfo(self::owner(), 'save', null)]);
        $capabilities->method('isClassLike')->willReturn(true);
        $scope = self::createStub(ScopeAtPositionInterface::class);
        $scope->method('variablesAt')->willReturn([new ResolvedVariable('variable', null)]);
        $symbols = self::createStub(SymbolSourceInterface::class);
        $symbols->method('childrenOf')->willReturn(new NamespaceContents());
        $symbols->method('search')->willReturn([QualifiedName::fromFullyQualified('Node')]);
        $session = self::capabilitiesProvider();
        $formatter = new CompletionItemFormatter(new ResolvedSymbolPresenter());

        return new CompositeCompletionSource(
            $at,
            new MemberCandidates($capabilities, $session, $formatter),
            new NamedArgumentCandidates($formatter),
            new VariableCandidates($scope, $formatter),
            new KeywordCandidates($formatter),
            new BuiltinTypeCandidates($formatter),
            new SymbolCandidates($symbols, $capabilities, $session, $formatter),
        );
    }

    private static function owner(): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified('Widget');
    }
}
