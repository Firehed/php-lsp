<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Completion\CompletionItemFormatter;
use Firehed\PhpLsp\Completion\InsertTextFormat;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Resolution\MemberAccess;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\MemberAccessKind;
use Firehed\PhpLsp\Resolution\ResolvedSymbolPresenter;
use Firehed\PhpLsp\Resolution\SymbolCapabilitiesInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberCandidates::class)]
final class MemberCandidatesTest extends TestCase
{
    use BuildsCompletionInputsTrait;

    /**
     * @return iterable<string, array{MemberAccessContext, list<string>}>
     */
    public static function contextCases(): iterable
    {
        yield 'instance, every member' => [
            self::context(MemberAccessKind::Instance, ''),
            ['getName', 'getCount', 'name'],
        ];
        yield 'instance, prefix' => [
            self::context(MemberAccessKind::Instance, 'get'),
            ['getName', 'getCount'],
        ];
        yield 'instance, prefix that matches nothing' => [
            self::context(MemberAccessKind::Instance, 'zzz'),
            [],
        ];
        yield 'parent, methods only' => [
            self::context(MemberAccessKind::Parent, '', self::owner()),
            ['getName', 'getCount'],
        ];
        yield 'static adds ::class' => [
            self::context(MemberAccessKind::Static, ''),
            ['getName', 'getCount', 'name', 'class'],
        ];
        yield 'static prefix that matches only class' => [
            self::context(MemberAccessKind::Static, 'cl'),
            ['class'],
        ];
        yield 'static prefix that misses class' => [
            self::context(MemberAccessKind::Static, 'get'),
            ['getName', 'getCount'],
        ];
    }

    /**
     * A member access on the owner, written in $from.
     */
    private static function context(
        MemberAccessKind $kind,
        string $prefix,
        ?ClasslikeName $from = null,
    ): MemberAccessContext {
        return new MemberAccessContext(new MemberAccess(new ClasslikeType(self::owner()), $from, $kind), $prefix);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('contextCases')]
    public function testOffersTheResolvedMembersTheContextAccepts(MemberAccessContext $context, array $expected): void
    {
        $symbols = $this->createMock(SymbolCapabilitiesInterface::class);
        $symbols->expects(self::once())
            ->method('getAccessibleMembers')
            ->with(
                $context->access->on,
                $context->access->from,
                $context->memberFilter(),
                $context->access->staticClass(),
            )
            ->willReturn([
                self::member(new MethodName(self::owner(), 'getName'), MemberKind::Method),
                self::member(new MethodName(self::owner(), 'getCount'), MemberKind::Method),
                self::member(new PropertyName(self::owner(), 'name'), MemberKind::Property),
            ]);

        $items = self::candidates($symbols)->find($context);

        self::assertSame(
            $expected,
            array_column($items, 'label'),
            'members the context accepts and the prefix matches, plus ::class on static access',
        );
    }

    public function testMethodsInsertASnippetWhenTheClientSupportsIt(): void
    {
        $symbols = self::createStub(SymbolCapabilitiesInterface::class);
        $symbols->method('getAccessibleMembers')->willReturn([
            self::member(new MethodName(self::owner(), 'getName'), MemberKind::Method),
        ]);

        $items = self::candidates($symbols, snippetSupport: true)
            ->find(self::context(MemberAccessKind::Instance, ''));

        self::assertSame(
            InsertTextFormat::Snippet->value,
            $items[0]['insertTextFormat'] ?? null,
            'the session snippet support reaches the method item',
        );
    }

    private static function candidates(
        SymbolCapabilitiesInterface $symbols,
        bool $snippetSupport = false,
    ): MemberCandidates {
        return new MemberCandidates(
            $symbols,
            self::capabilitiesProvider(new SessionCapabilities(snippetSupport: $snippetSupport)),
            new CompletionItemFormatter(new ResolvedSymbolPresenter()),
        );
    }

    private static function member(MethodName|PropertyName $name, MemberKind $kind): ResolvedMemberInterface
    {
        $member = self::createStub(ResolvedMemberInterface::class);
        $member->method('getName')->willReturn($name);
        $member->method('getMemberKind')->willReturn($kind);

        return $member;
    }

    private static function owner(): ClasslikeName
    {
        return ClasslikeName::fromFullyQualified('Fixtures\\Domain\\User');
    }
}
