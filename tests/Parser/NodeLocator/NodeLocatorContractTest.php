<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\NodeLocator;

use Closure;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeLocator\CursorTextNodeLocator;
use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\NodeLocator\TreeNodeLocator;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\AssertsTreeContractTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every node locator returns nodes that meet the guarantees ParsedDocument
 * states and that sit in the parsed tree it was handed, checked the same way
 * for each, on trees from each source.
 */
#[CoversNothing]
final class NodeLocatorContractTest extends TestCase
{
    use AssertsTreeContractTrait;
    use LoadsFixturesTrait;

    /**
     * Each case names a position the locator answers at.
     *
     * @return iterable<string, array{NodeLocatorInterface, SyntaxSourceInterface, string, Closure(self, string): int}>
     */
    public static function locatedNodes(): iterable
    {
        $tree = new TreeNodeLocator();
        $cursorText = new CursorTextNodeLocator();
        $parser = new PhpParserSyntaxSource(new TreeAnnotator());
        $skeleton = new SkeletonSyntaxSource();

        yield 'tree, parsed: method in a class' => [
            $tree, $parser, 'src/Inheritance/ChildClass.php', self::atHover('inherited_method'),
        ];
        yield 'tree, parsed: call in a function' => [
            $tree, $parser, 'SignatureHelp.php', self::atHover('typedVarMethod'),
        ];
        yield 'tree, parsed: call at file level' => [
            $tree, $parser, 'SignatureHelp.php', self::atHover('signatureHelpAdd'),
        ];
        yield 'tree, skeleton: method name' => [
            $tree, $skeleton, 'src/IncompleteCode/VeryBroken.php', self::atText('getName'),
        ];
        yield 'tree, skeleton: parent class name' => [
            $tree, $skeleton, 'src/Inheritance/ChildClass.php', self::atText('ParentClass'),
        ];
        yield 'cursor text, parsed: static call through an import' => [
            $cursorText, $parser, 'src/Resolution/CursorTextResolution.php', self::atCursor('aliased_static'),
        ];
        yield 'cursor text, parsed: imported function' => [
            $cursorText, $parser, 'src/Resolution/CursorTextResolution.php', self::atCursor('imported_function'),
        ];
        yield 'cursor text, parsed: attribute' => [
            $cursorText, $parser, 'src/Resolution/CursorTextResolution.php', self::atCursor('attribute'),
        ];
        yield 'cursor text, parsed: static access' => [
            $cursorText, $parser, 'src/Inheritance/ChildClass.php', self::atCursor('direct_parent_static'),
        ];
        yield 'cursor text, skeleton: instance access' => [
            $cursorText, $skeleton, 'src/IncompleteCode/VeryBroken.php', self::atCursor('this_in_if'),
        ];

        $production = ProductionSyntaxSource::create();
        yield 'production: a node the tree holds' => [
            $production->locator,
            $production->source,
            'src/Inheritance/ChildClass.php',
            self::atHover('inherited_method'),
        ];
        yield 'production: a node synthesized from text' => [
            $production->locator,
            $production->source,
            'src/Resolution/CursorTextResolution.php',
            self::atCursor('aliased_static'),
        ];
        yield 'production: a file only the skeleton reads' => [
            $production->locator,
            $production->source,
            'src/IncompleteCode/VeryBroken.php',
            self::atCursor('this_in_if'),
        ];
    }

    /**
     * @param Closure(self, string): int $offsetIn
     */
    #[DataProvider('locatedNodes')]
    public function testTheLocatedNodeMeetsTheContract(
        NodeLocatorInterface $locator,
        SyntaxSourceInterface $source,
        string $fixture,
        Closure $offsetIn,
    ): void {
        $content = $this->loadFixture($fixture);
        $parsed = $source->parse(new TextDocument('file:///' . $fixture, 'php', 1, $content));
        self::assertNotSame([], $parsed->tree, "{$fixture} must yield a tree from this source");
        $offset = $offsetIn($this, $content);

        $node = $locator->nodeAt($parsed, $offset);

        self::assertNotNull($node, "the locator must answer at offset {$offset} in {$fixture}");
        self::assertLocatedNodeMeetsContract($node, $parsed, $offset, "{$fixture} at {$offset}");
    }

    /**
     * @return Closure(self, string): int
     */
    private static function atCursor(string $marker): Closure
    {
        return fn (self $test, string $content): int => $test->markerOffset($content, $marker);
    }

    /**
     * @return Closure(self, string): int
     */
    private static function atHover(string $marker): Closure
    {
        return function (self $test, string $content) use ($marker): int {
            ['line' => $line, 'character' => $character] = $test->locateHoverMarker($content, $marker);
            return (new TextDocument('file:///t.php', 'php', 1, $content))->offsetAt($line, $character);
        };
    }

    /**
     * The first occurrence of $text.
     *
     * @return Closure(self, string): int
     */
    private static function atText(string $text): Closure
    {
        return function (self $test, string $content) use ($text): int {
            $offset = strpos($content, $text);
            self::assertNotFalse($offset, "the fixture must contain `{$text}`");
            return $offset;
        };
    }
}
