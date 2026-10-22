<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\Recovery\SkeletonRecovery;
use Firehed\PhpLsp\Parser\SyntaxSource\ParsingSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParsingSyntaxSource::class)]
final class ParsingSyntaxSourceTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testTheTreeIsPairedWithItsDocumentAndAnnotated(): void
    {
        $doc = $this->document('src/Domain/User.php');

        $result = self::source()->parse($doc);

        self::assertSame($doc, $result->document, 'the tree is paired with the document it was parsed from');
        $class = (new NodeFinder())->findFirstInstanceOf($result->tree, Class_::class);
        self::assertNotNull($class);
        self::assertInstanceOf(Namespace_::class, $class->getAttribute('parent'), 'parent links are set');
        $declared = $class->namespacedName;
        self::assertInstanceOf(Name::class, $declared, 'names are resolved');
        self::assertSame('Fixtures\\Domain\\User', $declared->toString());
    }

    public function testWhatRecoveryReadsIsTheTree(): void
    {
        $tree = self::source()->parse($this->document('Recovery/EndsAtClassKeyword.php'))->tree;

        self::assertNotNull(
            (new NodeFinder())->findFirstInstanceOf($tree, Namespace_::class),
            'code php-parser cannot read still yields the structure recovery found',
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

    private function document(string $fixture): TextDocument
    {
        return new TextDocument('file:///' . $fixture, 'php', 1, $this->loadFixture($fixture));
    }

    private static function source(): ParsingSyntaxSource
    {
        return new ParsingSyntaxSource(new PhpParserCodeParser(), new SkeletonRecovery(), new TreeAnnotator());
    }
}
