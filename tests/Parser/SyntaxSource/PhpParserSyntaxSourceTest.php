<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParseMetrics;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpParserSyntaxSource::class)]
final class PhpParserSyntaxSourceTest extends TestCase
{
    /**
     * Recoverable by the parser, but fatal to NameResolver, which runs with the
     * default throwing error handler.
     */
    private const string DUPLICATE_USE_ALIAS = "<?php\nnamespace A;\nuse B\\Foo;\nuse C\\Foo;\n";

    private ParseMetrics $metrics;
    private PhpParserSyntaxSource $source;

    protected function setUp(): void
    {
        $this->metrics = new ParseMetrics();
        $this->source = new PhpParserSyntaxSource(new TreeAnnotator(), $this->metrics);
    }

    public function testParseValidPhp(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function foo() {}');

        $result = $this->source->parse($doc);

        self::assertCount(1, $result);
        self::assertInstanceOf(Function_::class, $result[0]);
    }

    public function testParseClass(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php class MyClass { public function bar() {} }');

        $result = $this->source->parse($doc);

        self::assertCount(1, $result);
        self::assertInstanceOf(Class_::class, $result[0]);
    }

    public function testParseInvalidPhpUsesErrorRecovery(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function foo( { }');

        $result = $this->source->parse($doc);

        self::assertSame(
            [],
            $result,
            'a syntax error that stops recovery early yields the empty AST rather than throwing',
        );
    }

    public function testParseYieldsEmptyOnNameResolverFailure(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, self::DUPLICATE_USE_ALIAS);

        self::assertSame(
            [],
            $this->source->parse($doc),
            'a name-resolution failure yields no statements rather than a partial or null AST',
        );
    }

    public function testParseEmptyFile(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '');

        $result = $this->source->parse($doc);

        self::assertCount(0, $result);
    }

    /**
     * @return iterable<string, array{string}>
     * @codeCoverageIgnore data provider runs before coverage begins
     */
    public static function exitPaths(): iterable
    {
        yield 'valid code' => ['<?php class MyClass {}'];
        yield 'error-recovered code' => ['<?php function foo( { }'];
        yield 'no statements' => [''];
        yield 'name resolution throws' => [self::DUPLICATE_USE_ALIAS];
    }

    #[DataProvider('exitPaths')]
    public function testEveryParseExitIsMetered(string $content): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, $content);

        $this->source->parse($doc);

        self::assertSame(1, $this->metrics->getParseCount(), 'the parse is counted on this exit path');
    }

    public function testMeteredTimeCoversTheParse(): void
    {
        $doc = new TextDocument(
            'file:///test.php',
            'php',
            1,
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resolution/SymbolResolver.php'),
        );

        $startNs = hrtime(true);
        $this->source->parse($doc);
        $elapsedNs = hrtime(true) - $startNs;

        $recordedNs = $this->metrics->getTotalParseTimeNs();

        self::assertLessThanOrEqual($elapsedNs, $recordedNs, 'no more time can be metered than actually elapsed');
        self::assertGreaterThan(
            intdiv($elapsedNs, 2),
            $recordedNs,
            'the metered span covers the parse and both visitor passes, not a sliver around them',
        );
    }

    public function testParseReturnTypeIsNonNullable(): void
    {
        $return = (new \ReflectionMethod(PhpParserSyntaxSource::class, 'parse'))->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertFalse(
            $return->allowsNull(),
            'parse() must return array<Stmt> without null so no caller has to test or default it',
        );
        self::assertSame('array', $return->getName());
    }
}
