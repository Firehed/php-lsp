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
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeSyntaxSource::class)]
final class CompositeSyntaxSourceTest extends TestCase
{
    public function testReturnsTheFirstNonEmptyResult(): void
    {
        $composite = self::composite();

        $tree = $composite->parse(self::doc('<?php class Foo {}'));

        self::assertNotSame([], $tree, 'php-parser produced a tree, so that must reach the caller');
        self::assertNotSame(
            [],
            (new NodeFinder())->findInstanceOf($tree, Class_::class),
            'the winning tree came from php-parser and must carry its class node',
        );
    }

    public function testReturnsEmptyWhenEverySourceIsEmpty(): void
    {
        $composite = self::composite();

        self::assertSame(
            [],
            $composite->parse(self::doc('<?php')),
            'no source had a tree, so the composite reports the empty list its fallbacks would have seen',
        );
    }

    private static function composite(): CompositeSyntaxSource
    {
        return new CompositeSyntaxSource(
            new PhpParserSyntaxSource(new TreeAnnotator(), new ParseMetrics()),
            new SkeletonSyntaxSource(),
            new CursorTextSyntaxSource(),
        );
    }

    private static function doc(string $content): TextDocument
    {
        return new TextDocument('file:///t.php', 'php', 1, $content);
    }
}
