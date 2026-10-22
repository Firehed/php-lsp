<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Resolution\Position\CallSite;
use Firehed\PhpLsp\Resolution\Position\Located;
use Firehed\PhpLsp\Resolution\Position\Slot;
use Firehed\PhpLsp\Resolution\Scope;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Located::class)]
final class LocatedTest extends TestCase
{
    public function testConstruction(): void
    {
        $parsed = new ParsedDocument(new TextDocument('file:///t.php', 'php', 1, '<?php '), []);
        $offset = new ByteOffset(6);
        $written = new Variable('user');
        $receiver = new Variable('this');
        $scope = Scope::at(null, []);
        $call = new CallSite(new FuncCall(new Name('run')), 0, [], 0);

        $located = new Located($parsed, $offset, Slot::Member, 'us', $written, $receiver, $scope, $call);

        self::assertSame(
            [$parsed, $offset, Slot::Member, 'us', $written, $receiver, $scope, $call],
            [
                $located->parsed,
                $located->offset,
                $located->slot,
                $located->prefix,
                $located->written,
                $located->receiver,
                $located->scope,
                $located->call,
            ],
            'each part of what was located is kept',
        );
    }
}
