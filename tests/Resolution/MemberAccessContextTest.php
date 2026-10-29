<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemberAccessContext::class)]
class MemberAccessContextTest extends TestCase
{
    /**
     * @return iterable<string, array{MemberAccessKind, MemberFilter, bool, list<MemberKind>}>
     */
    public static function rules(): iterable
    {
        $every = MemberKind::cases();
        yield 'an instance access' => [MemberAccessKind::Instance, MemberFilter::Instance, false, $every];
        yield 'a static access' => [MemberAccessKind::Static, MemberFilter::Static, true, $every];
        yield 'a parent:: access' => [MemberAccessKind::Parent, MemberFilter::All, false, [MemberKind::Method]];
    }

    /**
     * @param list<MemberKind> $accepted
     */
    #[DataProvider('rules')]
    public function testCompletionRulesFollowTheAccessKind(
        MemberAccessKind $kind,
        MemberFilter $filter,
        bool $offersClassConstant,
        array $accepted,
    ): void {
        $target = new MemberTarget(
            new ClasslikeType(ClasslikeName::fromFullyQualified(self::class)),
            null,
            $kind,
        );

        $context = new MemberAccessContext($target, 'pre');

        self::assertSame($target, $context->target, 'what the access is');
        self::assertSame('pre', $context->prefix, 'what is typed of the member name');
        self::assertSame($filter, $context->memberFilter(), 'which members the lookup includes');
        self::assertSame($offersClassConstant, $context->offersClassConstant(), 'whether ::class is offered');
        foreach (MemberKind::cases() as $memberKind) {
            $member = self::createStub(ResolvedMemberInterface::class);
            $member->method('getMemberKind')->willReturn($memberKind);
            self::assertSame(
                in_array($memberKind, $accepted, true),
                $context->accepts($member),
                "whether a {$memberKind->name} is offered",
            );
        }
    }
}
