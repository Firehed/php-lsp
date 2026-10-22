<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionRequest;
use Firehed\PhpLsp\Document\TextDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionRequest::class)]
class CompletionRequestTest extends TestCase
{
    public function testTheOffsetIsWhereThePositionIsInTheText(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 0, "<?php\n\$fo");

        self::assertEquals(
            $document->offsetAt(1, 3),
            (new CompletionRequest($document, 1, 3))->offset,
            'the position is converted once, where the request is made',
        );
    }
}
