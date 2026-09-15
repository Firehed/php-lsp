<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Completion\KeywordCandidates;
use Firehed\PhpLsp\Completion\KeywordGroup;
use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeywordCandidates::class)]
final class KeywordCandidatesTest extends TestCase
{
    public function testExpressionGroupReturnsNullWhenPrefixIsVariable(): void
    {
        // The Expression group reads its prefix from callExpressionPrefix, which
        // returns null when the cursor sits on a variable. The composite guards
        // against this before dispatching, but the class's own contract still
        // promises null-when-not-applicable so a direct caller can rely on it.
        $doc = new TextDocument('file:///t.php', 'php', 0, "<?php\nfoo(\$va");
        $request = new CompletionRequest($doc, 1, 6);

        self::assertNull(
            (new KeywordCandidates())->find($request, KeywordGroup::Expression),
            'a variable prefix inside a call does not offer expression keywords',
        );
    }
}
