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
     */
    #[DataProvider('exitPaths')]
    public function testEveryParseExitIsMetered(string $content): void
    {
        $parser = new ParserService();
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        $parser->parse($doc);
        $parser->parse($doc);

        self::assertSame(2, $parser->getMetrics()->getParseCount(), 'both parses are counted on this path');
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
