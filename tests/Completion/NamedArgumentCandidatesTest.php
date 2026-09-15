<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\NamedArgumentCandidates;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Resolution\CodeResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NamedArgumentCandidates::class)]
final class NamedArgumentCandidatesTest extends TestCase
{
    public function testReturnsNullOutsideCallContext(): void
    {
        // The composite gates this before dispatching, but the class's contract
        // promises null-when-not-applicable so a direct caller can rely on it.
        $codeResolver = self::createStub(CodeResolverInterface::class);
        $codeResolver->method('getCallContext')->willReturn(null);

        $doc = new TextDocument('file:///t.php', 'php', 0, '<?php $x');
        $request = new CompletionRequest($doc, 0, 8);

        self::assertNull(
            (new NamedArgumentCandidates($codeResolver))->find($request),
            'a position outside any call offers no named arguments',
        );
    }
}
