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
use Firehed\PhpLsp\Resolution\MemberAccessContext;
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
        $type = new ClasslikeType(self::owner());
        yield 'instance, every member' => [
            MemberAccessContext::forInstance($type, null, ''),
            ['getName', 'getCount', 'name'],
        ];
        yield 'instance, prefix' => [
            MemberAccessContext::forInstance($type, null, 'get'),
            ['getName', 'getCount'],
        ];
        yield 'instance, prefix that matches nothing' => [
            MemberAccessContext::forInstance($type, null, 'zzz'),
            [],
        ];
        yield 'parent, methods only' => [
            MemberAccessContext::forParent($type, self::owner(), self::owner(), ''),
            ['getName', 'getCount'],
        ];
        yield 'static adds ::class' => [
            MemberAccessContext::forStatic($type, null, ''),
            ['getName', 'getCount', 'name', 'class'],
        ];
        yield 'static prefix that matches only class' => [
            MemberAccessContext::forStatic($type, null, 'cl'),
            ['class'],
        ];
        yield 'static prefix that misses class' => [
            MemberAccessContext::forStatic($type, null, 'get'),
            ['getName', 'getCount'],
        ];
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
            ->with($context->type, $context->from, $context->memberFilter, $context->static)
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
            ->find(MemberAccessContext::forInstance(new ClasslikeType(self::owner()), null, ''));

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
