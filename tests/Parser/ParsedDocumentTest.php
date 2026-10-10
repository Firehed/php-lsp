<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsedDocument::class)]
final class ParsedDocumentTest extends TestCase
{
    public function testConstruction(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 1, '<?php');
        $tree = [new Nop()];

        $parsed = new ParsedDocument($document, $tree);

        self::assertSame($document, $parsed->document, 'the document is kept as given');
        self::assertSame($tree, $parsed->tree, 'the tree is kept as given');
    }
}
