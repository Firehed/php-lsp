<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Resolution\Position\CallSite;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallSite::class)]
final class CallSiteTest extends TestCase
{
    public function testConstruction(): void
    {
        $call = new FuncCall(new Name('strlen'));

        $site = new CallSite($call, 2, ['name'], 1);

        self::assertSame($call, $site->call, 'the call');
        self::assertSame(2, $site->argumentIndex, 'the argument the position is in');
        self::assertSame(['name'], $site->usedNames, 'the arguments written by name');
        self::assertSame(1, $site->positionalCount, 'the arguments filled by position');
    }
}
