<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Capability\SessionCapabilitiesProviderInterface;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\MemberCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberCandidates::class)]
final class MemberCandidatesTest extends TestCase
{
    /**
     * @return iterable<string, array{MemberAccessContext, list<string>}>
     */
    public static function contextCases(): iterable
    {
        $type = new ClasslikeType(self::owner());
        yield 'instance, every member' => [
            MemberAccessContext::forInstance($type, Visibility::Public, ''),
            ['getName', 'getCount', 'name'],
        ];
        yield 'instance, prefix' => [
            MemberAccessContext::forInstance($type, Visibility::Public, 'get'),
            ['getName', 'getCount'],
        ];
        yield 'instance, prefix that matches nothing' => [
            MemberAccessContext::forInstance($type, Visibility::Public, 'zzz'),
            [],
        ];
        yield 'parent, methods only' => [
            MemberAccessContext::forParent($type, Visibility::Protected, ''),
            ['getName', 'getCount'],
        ];
        yield 'static adds ::class' => [
            MemberAccessContext::forStatic($type, Visibility::Public, ''),
            ['getName', 'getCount', 'name', 'class'],
        ];
        yield 'static prefix that misses class' => [
            MemberAccessContext::forStatic($type, Visibility::Public, 'get'),
            ['getName', 'getCount'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('contextCases')]
    public function testOffersTheResolvedMembersTheContextAccepts(MemberAccessContext $context, array $expected): void
    {
        $request = self::request();
        $codeResolver = $this->createMock(CodeResolverInterface::class);
        $codeResolver->method('getMemberAccessContext')->willReturn($context);
        $codeResolver->expects(self::once())
            ->method('getAccessibleMembers')
            ->with($request->document, $context->type, $context->minVisibility, $context->memberFilter)
            ->willReturn([
                self::member(new MethodName(self::owner(), 'getName'), MemberKind::Method),
                self::member(new MethodName(self::owner(), 'getCount'), MemberKind::Method),
                self::member(new PropertyName(self::owner(), 'name'), MemberKind::Property),
            ]);

        $items = self::candidates($codeResolver)->find($request);

        self::assertNotNull($items, 'a member access answers, even when nothing matches');
        self::assertSame(
            $expected,
            array_column($items, 'label'),
            'members the context accepts and the prefix matches, plus ::class on static access',
        );
    }

    public function testReturnsNullOutsideMemberAccess(): void
    {
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getMemberAccessContext')->willReturn(null);

        self::assertNull(
            self::candidates($codeResolver)->find(self::request()),
            'a position that is not a member access leaves completion to the other sources',
        );
    }

    private static function candidates(CodeResolverInterface $codeResolver): MemberCandidates
    {
        $capabilities = self::createStub(SessionCapabilitiesProviderInterface::class);
        $capabilities->method('getSessionCapabilities')->willReturn(new SessionCapabilities());

        return new MemberCandidates($codeResolver, $capabilities);
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

    private static function request(): CompletionRequest
    {
        return new CompletionRequest(new TextDocument('file:///t.php', 'php', 0, "<?php\n\$user->"), 1, 7);
    }
}
