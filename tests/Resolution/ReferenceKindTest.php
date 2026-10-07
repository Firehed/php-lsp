<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\ReferenceKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReferenceKind::class)]
final class ReferenceKindTest extends TestCase
{
    public function testNearerReferencesRankFirst(): void
    {
        $kinds = ReferenceKind::cases();
        usort($kinds, static fn (ReferenceKind $a, ReferenceKind $b): int => $a->priority() <=> $b->priority());

        self::assertSame(
            [
                ReferenceKind::CurrentNamespace,
                ReferenceKind::Import,
                ReferenceKind::PrefixImport,
                ReferenceKind::GlobalFallback,
                ReferenceKind::SubNamespace,
                ReferenceKind::Unreachable,
            ],
            $kinds,
            'a bare name ranks ahead of a qualified one, and a global fallback ahead of a sub-namespace path',
        );
    }

    public function testEachKindHasItsOwnPriority(): void
    {
        $priorities = array_map(static fn (ReferenceKind $kind): int => $kind->priority(), ReferenceKind::cases());

        self::assertSame(
            $priorities,
            array_values(array_unique($priorities)),
            'no two kinds tie, so the order is total',
        );
    }
}
