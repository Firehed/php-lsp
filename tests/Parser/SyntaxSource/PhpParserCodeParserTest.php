<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpParserCodeParser::class)]
final class PhpParserCodeParserTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testParseValidPhp(): void
    {
        $attempt = (new PhpParserCodeParser())->parse('<?php function foo() {}');

        self::assertCount(1, $attempt->tree);
        self::assertInstanceOf(Function_::class, $attempt->tree[0]);
        self::assertSame([], $attempt->errors, 'valid code reports no errors');
        self::assertNotSame([], $attempt->tokens, 'the attempt carries the tokens the code lexed to');
    }

    public function testParseClass(): void
    {
        $attempt = (new PhpParserCodeParser())->parse('<?php class MyClass { public function bar() {} }');

        self::assertCount(1, $attempt->tree);
        self::assertInstanceOf(Class_::class, $attempt->tree[0]);
    }

    public function testCodeTheParserCannotReadYieldsAnEmptyTreeAndItsErrors(): void
    {
        $attempt = (new PhpParserCodeParser())->parse('<?php function foo( { }');

        self::assertSame([], $attempt->tree, 'an error php-parser cannot recover from leaves no tree');
        self::assertNotSame([], $attempt->errors, 'the error that stopped it is reported');
    }

    public function testParseEmptyFile(): void
    {
        self::assertSame([], (new PhpParserCodeParser())->parse('')->tree);
    }

    public function testASemicolonNamespaceSpansToTheNextStatementOrTheEndOfTheFile(): void
    {
        $content = $this->loadFixture('src/TypeInference/MultiNamespaceFunction.php');
        [, $first, $second] = (new PhpParserCodeParser())->parse($content)->tree;

        self::assertInstanceOf(Namespace_::class, $first);
        self::assertInstanceOf(Namespace_::class, $second);
        self::assertSame(
            $second->getStartFilePos() - 1,
            $first->getEndFilePos(),
            'a namespace runs up to the one that follows it, past its own last statement',
        );
        self::assertSame(
            strlen($content) - 1,
            $second->getEndFilePos(),
            'the last namespace runs to the end of the file',
        );
    }

    public function testRecordsTheCommasSeparatingEachCallsArguments(): void
    {
        $content = $this->loadFixture('src/ParseHealth/ArgumentSeparators.php');
        $tree = (new PhpParserCodeParser())->parse($content)->tree;

        $calls = (new NodeFinder())->find($tree, static fn (Node $node): bool => $node instanceof CallLike
            || $node instanceof Attribute);
        $recorded = [];
        foreach ($calls as $call) {
            $separators = ParsedDocument::argumentSeparatorsOf($call);
            self::assertNotNull($separators, 'every call carries its argument separators, even when it has none');
            foreach ($separators as $position) {
                self::assertSame(
                    $call,
                    (new NodeAtPosition())->find(
                        $tree,
                        $position,
                        static fn (Node $node): bool => $node instanceof CallLike || $node instanceof Attribute,
                    ),
                    'a comma is recorded only by the innermost call that holds it',
                );
            }
            array_push($recorded, ...$separators);
        }
        sort($recorded);
        $expected = array_map(
            fn (string $marker): int => $this->markerOffset($content, $marker) - 1,
            [
                'method_1', 'method_2', 'static_1', 'new_1', 'new_2', 'nullsafe_1', 'attribute_1',
                'inner_1', 'outer_1', 'outer_2', 'outer_3', 'outer_4', 'attribute_2', 'anonymous_1',
            ],
        );
        sort($expected);

        self::assertSame(
            $expected,
            $recorded,
            'only the commas between a call\'s own arguments are recorded, never ones inside strings, comments, '
                . 'nested calls, arrays, or closures',
        );
    }
}
