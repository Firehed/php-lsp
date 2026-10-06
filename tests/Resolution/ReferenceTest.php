<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution;

use Firehed\PhpLsp\Resolution\Reference;
use Firehed\PhpLsp\Resolution\ReferenceKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Reference::class)]
final class ReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{ReferenceKind, bool}>
     */
    public static function kinds(): iterable
    {
        yield 'current namespace' => [ReferenceKind::CurrentNamespace, true];
        yield 'import' => [ReferenceKind::Import, true];
        yield 'prefix import' => [ReferenceKind::PrefixImport, true];
        yield 'sub-namespace' => [ReferenceKind::SubNamespace, true];
        yield 'global fallback' => [ReferenceKind::GlobalFallback, true];
        yield 'unreachable' => [ReferenceKind::Unreachable, false];
    }

    #[DataProvider('kinds')]
    public function testOnlyAnUnreachableReferenceIsUnreachable(ReferenceKind $kind, bool $reachable): void
    {
        self::assertSame(
            $reachable,
            (new Reference('Widget', $kind))->isReachable(),
            'every way of writing a name reaches it, except the unreachable fallback',
        );
    }
}
