<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\CompositeNodeLocator;
use Firehed\PhpLsp\Parser\SyntaxSource\CursorTextSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\TreeNodeLocator;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompositeNodeLocator::class)]
final class CompositeNodeLocatorTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string FIXTURE = 'src/Inheritance/ChildClass.php';

    public function testTheTreeAnswersFirst(): void
    {
        $parsed = (new PhpParserSyntaxSource(new TreeAnnotator()))->parse($this->document());
        $position = $this->locateHoverMarker($parsed->document->getContent(), 'inherited_method');
        $offset = $parsed->document->offsetAt($position['line'], $position['character']);

        self::assertSame(
            (new TreeNodeLocator())->nodeAt($parsed, $offset),
            self::composite()->nodeAt($parsed, $offset),
            'a node the tree holds is the answer',
        );
    }

    public function testTheCursorTextAnswersWhenTheTreeHasNothing(): void
    {
        $parsed = new ParsedDocument($this->document(), []);
        $offset = $this->markerOffset($parsed->document->getContent(), 'direct_parent_static');

        self::assertInstanceOf(
            StaticPropertyFetch::class,
            self::composite()->nodeAt($parsed, $offset)?->getAttribute('parent'),
            'with nothing in the tree, the access typed at the cursor is synthesized',
        );
    }

    public function testNothingAnswersWhereNeitherMemberDoes(): void
    {
        $parsed = new ParsedDocument($this->document(), []);

        self::assertNull(self::composite()->nodeAt($parsed, 0), 'the open tag names nothing');
    }

    private static function composite(): CompositeNodeLocator
    {
        return new CompositeNodeLocator(new TreeNodeLocator(), new CursorTextSyntaxSource());
    }

    private function document(): TextDocument
    {
        return new TextDocument('file:///' . self::FIXTURE, 'php', 1, $this->loadFixture(self::FIXTURE));
    }
}
