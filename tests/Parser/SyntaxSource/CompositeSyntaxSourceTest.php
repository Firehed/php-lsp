<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeSyntaxSource::class)]
final class CompositeSyntaxSourceTest extends TestCase
{
    private CompositeSyntaxSource $composite;

    protected function setUp(): void
    {
        $this->composite = new CompositeSyntaxSource(
            new PhpParserSyntaxSource(new TreeAnnotator(), new ParseMetrics()),
            new SkeletonSyntaxSource(),
            new CursorTextSyntaxSource(),
        );
    }

    /**
     * Duplicate `use` aliases make php-parser's NameResolver throw, which
     * {@see PhpParserSyntaxSource} converts to the empty list. The skeleton
     * recovers the namespace from the text, so the composite must return
     * that namespace rather than the empty list php-parser would have
     * given on its own.
     */
    public function testAnEarlierEmptyLetsTheNextSourceAnswer(): void
    {
        $document = new TextDocument(
            'file:///t.php',
            'php',
            1,
            "<?php\nnamespace A;\nuse B\\Foo;\nuse C\\Foo;\n",
        );

        $tree = $this->composite->parse($document);

        self::assertCount(1, $tree, 'the skeleton fills in the tree php-parser refused');
        self::assertInstanceOf(
            Namespace_::class,
            $tree[0],
            'the skeleton reconstructs the namespace declaration from the text',
        );
    }

    /**
     * Unrecoverable syntax with no classlike or namespace declaration: php-parser
     * bails out to the empty list, the skeleton has no structure to recover, and
     * the cursor-text source only serves nodeAt. The composite must report that
     * empty list rather than fabricate one.
     */
    public function testReturnsEmptyWhenEverySourceIsEmpty(): void
    {
        $document = new TextDocument('file:///t.php', 'php', 1, '<?php function foo( { }');

        self::assertSame(
            [],
            $this->composite->parse($document),
            'no source had a tree, so the composite reports the empty list its fallbacks would have seen',
        );
    }
}
