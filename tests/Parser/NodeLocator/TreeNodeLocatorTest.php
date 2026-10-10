<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\NodeLocator;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Parser\NodeLocator\TreeNodeLocator;
use Firehed\PhpLsp\Parser\ParsedDocument;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SkeletonSyntaxSource;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Parser\TreeAnnotator;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PhpParser\Node\Identifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TreeNodeLocator::class)]
final class TreeNodeLocatorTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string FIXTURE = 'src/Inheritance/ChildClass.php';

    public function testFindsTheInnermostNodeAtTheOffset(): void
    {
        $parsed = $this->parse(new PhpParserSyntaxSource(new TreeAnnotator()));
        $position = $this->locateHoverMarker($parsed->document->getContent(), 'inherited_method');

        $node = (new TreeNodeLocator())->nodeAt(
            $parsed,
            $parsed->document->offsetAt($position['line'], $position['character']),
        );

        self::assertInstanceOf(Identifier::class, $node, 'the method name is the innermost node');
        self::assertSame('parentMethod', $node->toString());
    }

    #[DataProvider('treeSources')]
    public function testAStatementIsNoAnswer(SyntaxSourceInterface $source): void
    {
        $parsed = $this->parse($source);

        self::assertNull(
            (new TreeNodeLocator())->nodeAt($parsed, self::offsetOf($parsed, 'class ChildClass')),
            'a statement holds nothing the cursor names',
        );
    }

    #[DataProvider('treeSources')]
    public function testTheLineBeforeADeclarationIsNoAnswer(SyntaxSourceInterface $source): void
    {
        $parsed = $this->parse($source);

        self::assertNull(
            (new TreeNodeLocator())->nodeAt($parsed, self::offsetOf($parsed, 'class ChildClass') - 1),
            'the blank line before a declaration names nothing',
        );
    }

    /**
     * The locator reads any tree that meets the ParsedDocument guarantees,
     * whichever source produced it.
     */
    #[DataProvider('treeSources')]
    public function testFindsANodeInATreeFromAnySource(SyntaxSourceInterface $source): void
    {
        $parsed = $this->parse($source);

        $node = (new TreeNodeLocator())->nodeAt($parsed, self::offsetOf($parsed, 'ChildClass'));

        self::assertInstanceOf(Identifier::class, $node, 'the declared class name is the innermost node');
        self::assertSame('ChildClass', $node->toString());
    }

    /**
     * @return array<string, array{SyntaxSourceInterface}>
     */
    public static function treeSources(): array
    {
        return [
            'php-parser' => [new PhpParserSyntaxSource(new TreeAnnotator())],
            'skeleton' => [new SkeletonSyntaxSource()],
        ];
    }

    public function testAnEmptyTreeHasNoNode(): void
    {
        $document = new TextDocument('file:///empty.php', 'php', 1, '');

        self::assertNull(
            (new TreeNodeLocator())->nodeAt(new ParsedDocument($document, []), 0),
            'nothing is at any offset of an empty tree',
        );
    }

    private function parse(SyntaxSourceInterface $source): ParsedDocument
    {
        return $source->parse(new TextDocument(
            'file:///' . self::FIXTURE,
            'php',
            1,
            $this->loadFixture(self::FIXTURE),
        ));
    }

    private static function offsetOf(ParsedDocument $parsed, string $needle): int
    {
        $offset = strpos($parsed->document->getContent(), $needle);
        self::assertNotFalse($offset, "the fixture must contain `{$needle}`");

        return $offset;
    }
}
