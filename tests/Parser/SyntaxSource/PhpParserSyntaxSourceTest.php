<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpParserSyntaxSource::class)]
final class PhpParserSyntaxSourceTest extends TestCase
{
    /**
     * Recoverable by the parser, but fatal to NameResolver, which runs with the
     * default throwing error handler.
     */
    private const string DUPLICATE_USE_ALIAS = "<?php\nnamespace A;\nuse B\\Foo;\nuse C\\Foo;\n";

    private PhpParserSyntaxSource $source;

    protected function setUp(): void
    {
        $this->source = new PhpParserSyntaxSource(new TreeAnnotator());
    }

    public function testParseValidPhp(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function foo() {}');

        $result = $this->source->parse($doc);

        self::assertSame($doc, $result->document, 'the tree is paired with the document it was parsed from');
        self::assertCount(1, $result->tree);
        self::assertInstanceOf(Function_::class, $result->tree[0]);
    }

    public function testParseClass(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php class MyClass { public function bar() {} }');

        $result = $this->source->parse($doc);

        self::assertCount(1, $result->tree);
        self::assertInstanceOf(Class_::class, $result->tree[0]);
    }

    public function testParseInvalidPhpUsesErrorRecovery(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function foo( { }');

        $result = $this->source->parse($doc);

        self::assertSame($doc, $result->document, 'an empty parse is still paired with its document');
        self::assertSame(
            [],
            $result->tree,
            'a syntax error that stops recovery early yields the empty AST rather than throwing',
        );
    }

    public function testParseYieldsEmptyOnNameResolverFailure(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, self::DUPLICATE_USE_ALIAS);

        self::assertSame(
            [],
            $this->source->parse($doc)->tree,
            'a name-resolution failure yields no statements rather than a partial or null AST',
        );
    }

    public function testParseEmptyFile(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '');

        $result = $this->source->parse($doc);

        self::assertCount(0, $result->tree);
    }

    public function testParseReturnTypeIsNonNullable(): void
    {
        $return = (new \ReflectionMethod(PhpParserSyntaxSource::class, 'parse'))->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertFalse(
            $return->allowsNull(),
            'parse() must return a ParsedDocument without null so no caller has to test or default it',
        );
        self::assertSame(ParsedDocument::class, $return->getName());
    }
}
