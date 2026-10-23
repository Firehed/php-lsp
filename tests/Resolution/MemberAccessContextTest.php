<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Tests\ProvidesMemberKindsTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessContext::class)]
class MemberAccessContextTest extends TestCase
{
    use ProvidesMemberKindsTrait;

    private static function access(MemberAccessKind $kind): MemberAccess
    {
        return new MemberAccess(
            new ClasslikeType(ClasslikeName::fromFullyQualified(self::class)),
            null,
            $kind,
        );
    }

    /**
     * @return iterable<string, array{MemberAccessKind, MemberFilter, bool}>
     */
    public static function rulesByKind(): iterable
    {
        yield 'an instance offers instance members' => [MemberAccessKind::Instance, MemberFilter::Instance, false];
        yield 'a static access offers static members and ::class' => [
            MemberAccessKind::Static,
            MemberFilter::Static,
            true,
        ];
        yield 'parent:: offers members of either kind' => [MemberAccessKind::Parent, MemberFilter::All, false];
    }

    #[DataProvider('rulesByKind')]
    public function testTheKindOfAccessDecidesWhatIsOffered(
        MemberAccessKind $kind,
        MemberFilter $filter,
        bool $offersClassConstant,
    ): void {
        $access = self::access($kind);
        $ctx = new MemberAccessContext($access, 'prefix');

        self::assertSame($access, $ctx->access, 'the access completed on');
        self::assertSame('prefix', $ctx->prefix, 'what is typed so far');
        self::assertSame($filter, $ctx->memberFilter, 'static or instance members');
        self::assertSame($offersClassConstant, $ctx->offersClassConstant, '::class');
    }

    #[DataProvider('allMemberKinds')]
    public function testInstanceAcceptsAllKinds(MemberKind $memberKind): void
    {
        $ctx = new MemberAccessContext(self::access(MemberAccessKind::Instance), '');
        $member = self::createStub(ResolvedMemberInterface::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertTrue($ctx->accepts($member), "instance should accept $memberKind->name");
    }

    #[DataProvider('allMemberKinds')]
    public function testStaticAcceptsAllKinds(MemberKind $memberKind): void
    {
        $ctx = new MemberAccessContext(self::access(MemberAccessKind::Static), '');
        $member = self::createStub(ResolvedMemberInterface::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertTrue($ctx->accepts($member), "static should accept $memberKind->name");
    }

    #[DataProvider('parentAcceptsCases')]
    public function testParentAcceptsOnlyMethods(MemberKind $memberKind, bool $expected): void
    {
        $ctx = new MemberAccessContext(self::access(MemberAccessKind::Parent), '');
        $member = self::createStub(ResolvedMemberInterface::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertSame($expected, $ctx->accepts($member), "parent accepts $memberKind->name");
    }

    /**
     * @return iterable<string, array{MemberKind, bool}>
     */
    public static function parentAcceptsCases(): iterable
    {
        yield 'Method' => [MemberKind::Method, true];
        yield 'Property' => [MemberKind::Property, false];
        yield 'Constant' => [MemberKind::Constant, false];
        yield 'EnumCase' => [MemberKind::EnumCase, false];
    }
}
