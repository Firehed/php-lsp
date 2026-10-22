<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\ParsingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsingSyntaxSource::class)]
final class ParsingSyntaxSourceTest extends TestCase
{
    public function testPairsTheTreeWithItsDocument(): void
    {
        $doc = new TextDocument('file:///test.php', 'php', 1, '<?php function foo() {}');

        $result = self::source()->parse($doc);

        self::assertSame($doc, $result->document, 'the tree is paired with the document it was parsed from');
        self::assertCount(1, $result->tree);
    }

    public function testTheTreeIsAnnotated(): void
    {
        $code = '<?php namespace A; use B\\Bar; class Foo { function f() { new Bar(); } }';
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);

        $tree = self::source()->parse($doc)->tree;

        $class = (new NodeFinder())->findFirstInstanceOf($tree, Class_::class);
        self::assertNotNull($class);
        self::assertInstanceOf(Namespace_::class, $class->getAttribute('parent'), 'parent links are set');
        $new = (new NodeFinder())->findFirstInstanceOf($tree, New_::class);
        self::assertNotNull($new);
        self::assertInstanceOf(Name::class, $new->class);
        self::assertSame('B\\Bar', $new->class->toString(), 'names are resolved against the imports');
    }

    public function testWhatRecoveryReadsIsTheTree(): void
    {
        $code = "<?php\nnamespace A;\nclass Foo\n{\n    public function bar(";
        $doc = new TextDocument('file:///test.php', 'php', 1, $code);

        $tree = self::source()->parse($doc)->tree;

        $class = (new NodeFinder())->findFirstInstanceOf($tree, Class_::class);
        self::assertNotNull($class, 'code php-parser cannot read still yields the structure recovery found');
        self::assertInstanceOf(
            Namespace_::class,
            $class->getAttribute('parent'),
            'and it is annotated like any tree',
        );
    }

    public function testParseReturnTypeIsNonNullable(): void
    {
        $return = (new \ReflectionMethod(ParsingSyntaxSource::class, 'parse'))->getReturnType();

        self::assertInstanceOf(\ReflectionNamedType::class, $return);
        self::assertFalse(
            $return->allowsNull(),
            'parse() must return a ParsedDocument without null so no caller has to test or default it',
        );
        self::assertSame(ParsedDocument::class, $return->getName());
    }

    private static function source(): ParsingSyntaxSource
    {
        return new ParsingSyntaxSource(new PhpParserCodeParser(), new SkeletonRecovery(), new TreeAnnotator());
    }
}
