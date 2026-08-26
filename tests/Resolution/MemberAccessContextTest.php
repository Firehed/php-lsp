<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\Visibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessContext::class)]
class MemberAccessContextTest extends TestCase
{
    private static function type(): ClassName
    {
        return new ClassName(self::class);
    }

    public function testForInstanceSetsExpectedValues(): void
    {
        $ctx = MemberAccessContext::forInstance(self::type(), Visibility::Public, 'prefix');

        self::assertSame(MemberAccessKind::Instance, $ctx->kind, 'kind');
        self::assertSame(MemberFilter::Instance, $ctx->memberFilter, 'memberFilter');
        self::assertFalse($ctx->offersClassConstant, 'offersClassConstant');
        self::assertSame('prefix', $ctx->prefix, 'prefix');
    }

    public function testForStaticSetsExpectedValues(): void
    {
        $ctx = MemberAccessContext::forStatic(self::type(), Visibility::Protected, 'pre');

        self::assertSame(MemberAccessKind::Static, $ctx->kind, 'kind');
        self::assertSame(MemberFilter::Static, $ctx->memberFilter, 'memberFilter');
        self::assertTrue($ctx->offersClassConstant, 'offersClassConstant');
        self::assertSame('pre', $ctx->prefix, 'prefix');
    }

    public function testForParentSetsExpectedValues(): void
    {
        $ctx = MemberAccessContext::forParent(self::type(), Visibility::Private, '');

        self::assertSame(MemberAccessKind::Parent, $ctx->kind, 'kind');
        self::assertSame(MemberFilter::All, $ctx->memberFilter, 'memberFilter');
        self::assertFalse($ctx->offersClassConstant, 'offersClassConstant');
        self::assertSame('', $ctx->prefix, 'prefix');
    }

    #[DataProvider('instanceAcceptsCases')]
    public function testInstanceAcceptsAllKinds(MemberKind $memberKind): void
    {
        $ctx = MemberAccessContext::forInstance(self::type(), Visibility::Public, '');
        $member = self::createStub(ResolvedMember::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertTrue($ctx->accepts($member), "instance should accept $memberKind->name");
    }

    #[DataProvider('staticAcceptsCases')]
    public function testStaticAcceptsAllKinds(MemberKind $memberKind): void
    {
        $ctx = MemberAccessContext::forStatic(self::type(), Visibility::Public, '');
        $member = self::createStub(ResolvedMember::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertTrue($ctx->accepts($member), "static should accept $memberKind->name");
    }

    #[DataProvider('parentAcceptsCases')]
    public function testParentAcceptsOnlyMethods(MemberKind $memberKind, bool $expected): void
    {
        $ctx = MemberAccessContext::forParent(self::type(), Visibility::Public, '');
        $member = self::createStub(ResolvedMember::class);
        $member->method('getMemberKind')->willReturn($memberKind);

        self::assertSame($expected, $ctx->accepts($member), "parent accepts $memberKind->name");
    }

    /**
     * @return iterable<string, array{MemberKind}>
     */
    public static function instanceAcceptsCases(): iterable
    {
        foreach (MemberKind::cases() as $kind) {
            yield $kind->name => [$kind];
        }
    }

    /**
     * @return iterable<string, array{MemberKind}>
     */
    public static function staticAcceptsCases(): iterable
    {
        foreach (MemberKind::cases() as $kind) {
            yield $kind->name => [$kind];
        }
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
