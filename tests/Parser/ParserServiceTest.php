<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParserService;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParserService::class)]
class ParserServiceTest extends TestCase
{
    /**
     * Recoverable by the parser, but fatal to NameResolver, which runs with the
     * default throwing error handler.
     */
    private const DUPLICATE_USE_ALIAS = "<?php\nnamespace A;\nuse B\\Foo;\nuse C\\Foo;\n";

    public function testParseValidPhp(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php function foo() {}',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(Function_::class, $result[0]);
    }

    public function testParseClass(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php class MyClass { public function bar() {} }',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(Class_::class, $result[0]);
    }

    public function testParseInvalidPhpUsesErrorRecovery(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '<?php function foo( { }', // syntax error
        );

        $result = $parser->parse($doc);

        // With error recovery, we get partial results instead of null
        self::assertIsArray($result);
    }

    public function testParseThrowsOnDuplicateUseAlias(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            self::DUPLICATE_USE_ALIAS,
        );

        // The parser recovers, but NameResolver throws: the null return is the
        // only signal callers get that resolution failed.
        self::assertNull($parser->parse($doc), 'a name-resolution failure yields null, not a partial AST');
    }

    /**
     * Every exit from parse() must be metered — otherwise the reparse counts the
     * caching decision rests on undercount whatever path the failure took.
     *
     * The scope is discarded between the two parses because within one scope the
     * second call is a memo hit and never reaches the meter.
     */
    #[DataProvider('exitPaths')]
    public function testEveryParseExitIsMetered(string $content): void
    {
        $parser = new ParserService();
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        $parser->parse($doc);
        $parser->discardScopedParses();
        $parser->parse($doc);

        self::assertSame(2, $parser->getMetrics()->getParseCount(), 'both parses are counted on this path');
    }

    /**
     * Every exit from parse() must also be memoized, including the ones that
     * produce no usable AST: a failure that reparses on every call is the cost
     * the dedup exists to remove.
     */
    #[DataProvider('exitPaths')]
    public function testEveryParseExitIsMemoized(string $content): void
    {
        $parser = new ParserService();
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        $first = $parser->parse($doc);
        $second = $parser->parse($doc);

        self::assertSame(1, $parser->getMetrics()->getParseCount(), 'the second call is answered from the memo');
        self::assertSame($first, $second, 'the memo returns what the parse returned, on every exit path');
    }

    public function testDifferingContentIsParsedSeparately(): void
    {
        $parser = new ParserService();

        $parser->parse(new TextDocument('file:///test.php', 'php', 1, '<?php class A {}'));
        $parser->parse(new TextDocument('file:///test.php', 'php', 2, '<?php class B {}'));

        self::assertSame(2, $parser->getMetrics()->getParseCount(), 'an edit invalidates nothing — it is a new key');
    }

    /**
     * The AST is a function of content alone: parse() reads nothing else off the
     * document. Two documents holding the same content therefore share one parse.
     */
    public function testIdenticalContentSharesOneParseAcrossDocuments(): void
    {
        $parser = new ParserService();
        $content = '<?php class Shared {}';

        $first = $parser->parse(new TextDocument('file:///a.php', 'php', 1, $content));
        $second = $parser->parse(new TextDocument('file:///b.php', 'php', 7, $content));

        self::assertSame(1, $parser->getMetrics()->getParseCount(), 'identical content is parsed once');
        self::assertSame($first, $second, 'both documents get the same AST');
    }

    /**
     * The memo is scoped to one handled LSP message, not standing: discarding it
     * is what keeps it from becoming the version-keyed cache the Step 0 spike
     * declined to add.
     */
    public function testDiscardingScopedParsesForcesAReparse(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php class MyClass {}');

        $parser->parse($doc);
        $parser->discardScopedParses();
        $parser->parse($doc);

        self::assertSame(2, $parser->getMetrics()->getParseCount(), 'the discarded parse is not retained');
    }

    /**
     * @return iterable<string, array{string}>
     *
     * @codeCoverageIgnore
     */
    public static function exitPaths(): iterable
    {
        yield 'valid code' => ['<?php class MyClass {}'];
        yield 'error-recovered code' => ['<?php function foo( { }'];
        yield 'no statements' => [''];
        yield 'name resolution throws' => [self::DUPLICATE_USE_ALIAS];
    }

    public function testMeteredTimeCoversTheParse(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            (string) file_get_contents(dirname(__DIR__, 2) . '/src/Resolution/SymbolResolver.php'),
        );

        $startNs = hrtime(true);
        $parser->parse($doc);
        $elapsedNs = hrtime(true) - $startNs;

        $recordedNs = $parser->getMetrics()->getTotalParseTimeNs();

        self::assertLessThanOrEqual($elapsedNs, $recordedNs, 'no more time can be metered than actually elapsed');
        self::assertGreaterThan(
            intdiv($elapsedNs, 2),
            $recordedNs,
            'the metered span covers the parse and both visitor passes, not a sliver around them',
        );
    }

    public function testParseEmptyFile(): void
    {
        $parser = new ParserService();
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            '',
        );

        $result = $parser->parse($doc);

        self::assertNotNull($result);
        self::assertCount(0, $result);
    }
}
