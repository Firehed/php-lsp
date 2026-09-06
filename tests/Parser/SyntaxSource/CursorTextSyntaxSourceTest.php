<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name\FullyQualified;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CursorTextSyntaxSource::class)]
class CursorTextSyntaxSourceTest extends TestCase
{
    public function testParseYieldsAnEmptyTree(): void
    {
        $source = new CursorTextSyntaxSource();
        $document = new TextDocument('file:///empty.php', 'php', 1, '<?php $x = 1;');

        self::assertSame(
            [],
            $source->parse($document),
            'parse() carries no tree; the cursor-text source is nodeAt-only',
        );
    }

    public function testNodeAtRejectsAnOffsetOutsideTheDocument(): void
    {
        $source = new CursorTextSyntaxSource();
        $document = new TextDocument('file:///doc.php', 'php', 1, '<?php $this->');

        self::assertNull($source->nodeAt([], $document, -1), 'a negative offset falls outside the document');
        self::assertNull(
            $source->nodeAt([], $document, strlen($document->getContent()) + 1),
            'an offset past the last byte falls outside the document',
        );
    }

    public function testAFullyQualifiedStaticAccessBecomesAFullyQualifiedReceiver(): void
    {
        $source = new CursorTextSyntaxSource();
        // A cursor on the class-name portion of `\Foo::` — NodeAtPosition
        // returns the innermost hit, so a cursor here lands on the receiver
        // node the buildStatic FullyQualified branch built.
        $content = "<?php\n\\Foo::";
        $document = new TextDocument('file:///fq.php', 'php', 1, $content);
        $fooOffset = strpos($content, 'Foo');
        self::assertNotFalse($fooOffset);
        $offset = $fooOffset + 1;

        $node = $source->nodeAt([], $document, $offset);
        self::assertInstanceOf(
            FullyQualified::class,
            $node,
            'a leading-separator name keeps the fully-qualified shape php-parser emits',
        );
        self::assertSame('Foo', $node->toString(), 'the leading separator is dropped from the stored name');
        $parent = $node->getAttribute('parent');
        self::assertInstanceOf(
            StaticPropertyFetch::class,
            $parent,
            'the FullyQualified receiver is attached to a StaticPropertyFetch',
        );
    }
}
