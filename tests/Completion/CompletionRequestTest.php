<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionKind;
use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionRequest::class)]
class CompletionRequestTest extends TestCase
{
    public function testTextBeforeCursorReadsFromDocument(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n\$fo");
        $request = new CompletionRequest($document, 1, 3);

        self::assertSame('$fo', $request->textBeforeCursor(), 'text before cursor should match document');
    }

    public function testTextBeforeCursorIsMemoized(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n\$fo");
        $request = new CompletionRequest($document, 1, 3);

        $first = $request->textBeforeCursor();
        $second = $request->textBeforeCursor();

        self::assertSame($first, $second, 'repeated calls return the same string');
    }

    public function testClassificationReflectsTextBeforeCursor(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n\$fo");
        $request = new CompletionRequest($document, 1, 3);

        $classification = $request->classification();

        self::assertSame(CompletionKind::Variable, $classification->kind, 'variable prefix classifies as Variable');
        self::assertSame('fo', $classification->prefix, 'prefix is the text after $');
    }

    public function testClassificationIsMemoized(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n\$fo");
        $request = new CompletionRequest($document, 1, 3);

        $first = $request->classification();
        $second = $request->classification();

        self::assertSame($first, $second, 'repeated calls return the same instance');
    }
}
