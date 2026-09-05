<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\MemoizingSyntaxSource;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Nop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoizingSyntaxSource::class)]
final class MemoizingSyntaxSourceTest extends TestCase
{
    public function testIdenticalContentParsesOnce(): void
    {
        $inner = self::countingInner();
        $memo = new MemoizingSyntaxSource($inner);
        $doc = self::doc('<?php class A {}');

        $first = $memo->parse($doc);
        $second = $memo->parse($doc);

        self::assertSame(1, $inner->parseCount, 'the second call is answered from the memo');
        self::assertSame($first, $second, 'the memo returns what the inner parse returned');
    }

    public function testDifferingContentIsParsedSeparately(): void
    {
        $inner = self::countingInner();
        $memo = new MemoizingSyntaxSource($inner);

        $memo->parse(self::doc('<?php class A {}'));
        $memo->parse(self::doc('<?php class B {}'));

        self::assertSame(2, $inner->parseCount, 'an edit invalidates nothing - it is a new key');
    }

    public function testIdenticalContentSharesOneParseAcrossDocuments(): void
    {
        $inner = self::countingInner();
        $memo = new MemoizingSyntaxSource($inner);
        $content = '<?php class Shared {}';

        $first = $memo->parse(new TextDocument('file:///a.php', 'php', 1, $content));
        $second = $memo->parse(new TextDocument('file:///b.php', 'php', 7, $content));

        self::assertSame(1, $inner->parseCount, 'identical content is parsed once, regardless of URI');
        self::assertSame($first, $second, 'both documents get the same tree');
    }

    public function testEndMessageForcesAReparse(): void
    {
        $inner = self::countingInner();
        $memo = new MemoizingSyntaxSource($inner);
        $doc = self::doc('<?php class MyClass {}');

        $memo->parse($doc);
        $memo->endMessage();
        $memo->parse($doc);

        self::assertSame(2, $inner->parseCount, 'the memo was discarded at the message boundary');
    }

    public function testEveryParseExitIsMemoizedIncludingTheEmptyOne(): void
    {
        $inner = self::countingInner([]);
        $memo = new MemoizingSyntaxSource($inner);
        $doc = self::doc('<?php broken(');

        $first = $memo->parse($doc);
        $second = $memo->parse($doc);

        self::assertSame(
            1,
            $inner->parseCount,
            'an empty tree is memoized like any other, so a failure does not reparse',
        );
        self::assertSame($first, $second, 'the second call returns the same empty list');
    }

    /**
     * @param array<Stmt>|null $return Defaults to a single Nop.
     */
    private static function countingInner(?array $return = null): CountingSyntaxSource
    {
        return new CountingSyntaxSource($return ?? [new Nop()]);
    }

    private static function doc(string $content): TextDocument
    {
        return new TextDocument('file:///t.php', 'php', 1, $content);
    }
}
