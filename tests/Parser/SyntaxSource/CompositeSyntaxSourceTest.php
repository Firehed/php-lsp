<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSource;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeSyntaxSource::class)]
final class CompositeSyntaxSourceTest extends TestCase
{
    public function testReturnsTheFirstNonEmptyResult(): void
    {
        $first = self::stubReturning([]);
        $second = self::stubReturning([new Nop()]);
        $third = self::stubReturning([new Nop(), new Nop()]);

        $composite = new CompositeSyntaxSource([$first, $second, $third]);

        $result = $composite->parse(self::doc('<?php'));

        self::assertCount(1, $result, 'the second source wins because it is the first non-empty answer');
    }

    public function testReturnsEmptyWhenEverySourceIsEmpty(): void
    {
        $composite = new CompositeSyntaxSource([
            self::stubReturning([]),
            self::stubReturning([]),
        ]);

        self::assertSame(
            [],
            $composite->parse(self::doc('<?php')),
            'no source had a tree, so the composite reports the empty list its fallbacks would have seen',
        );
    }

    public function testStopsAskingSourcesAfterTheFirstNonEmpty(): void
    {
        $winner = self::stubReturning([new Nop()]);
        $later = new class implements SyntaxSource {
            public bool $called = false;

            /**
             * @return array<Stmt>
             */
            public function parse(TextDocument $document): array
            {
                $this->called = true;
                return [];
            }
        };

        (new CompositeSyntaxSource([$winner, $later]))->parse(self::doc('<?php'));

        self::assertFalse($later->called, 'sources after the winner must not be asked');
    }

    public function testReturnsEmptyWhenNoSourcesAreConfigured(): void
    {
        self::assertSame(
            [],
            (new CompositeSyntaxSource([]))->parse(self::doc('<?php')),
            'zero sources means nothing to ask; the empty list is the only truthful answer',
        );
    }

    /**
     * @param array<Stmt> $tree
     */
    private static function stubReturning(array $tree): SyntaxSource
    {
        return new class ($tree) implements SyntaxSource {
            /**
             * @param array<Stmt> $tree
             */
            public function __construct(private readonly array $tree)
            {
            }

            /**
             * @return array<Stmt>
             */
            public function parse(TextDocument $document): array
            {
                return $this->tree;
            }
        };
    }

    private static function doc(string $content): TextDocument
    {
        return new TextDocument('file:///t.php', 'php', 1, $content);
    }
}
