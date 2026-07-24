<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Document;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The position round-trip corpus (RFC 1 §4.9, Step 1 acceptance): positions must
 * survive a round trip through the document boundary under the negotiated
 * encoding. {@see TextDocumentTest} pins specific multibyte conversions; this
 * corpus proves the boundary is a bijection across a whole file of mixed-width
 * codepoints, and that the existing cursor-marker fixtures address the same byte
 * the interior resolves once their wire column is converted.
 */
#[CoversClass(TextDocument::class)]
class PositionRoundTripCorpusTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testBoundaryIsABijectionAcrossTheMultibyteCorpus(): void
    {
        $content = $this->loadFixture('src/Encoding/Multibyte.php');
        $document = new TextDocument('file:///test.php', 'php', 1, $content);

        // Every codepoint boundary in the file, byte offsets included. A byte offset
        // that lands on a boundary must convert to a wire position and back to the
        // same byte offset; anything else would misplace the cursor past a
        // multibyte character.
        $offset = 0;
        foreach (mb_str_split($content, 1, 'UTF-8') as $codepoint) {
            $this->assertRoundTrips($document, $offset);
            $offset += strlen($codepoint);
        }
        // The end-of-file boundary is a valid cursor position too.
        $this->assertRoundTrips($document, $offset);
    }

    #[DataProvider('existingCursorMarkerCases')]
    public function testExistingCursorMarkerRoundTripsUnderNegotiatedEncoding(
        string $fixture,
        string $marker,
    ): void {
        $content = $this->loadFixture($fixture);
        $document = new TextDocument('file:///test.php', 'php', 1, $content);
        $wire = $this->locateCursorUtf16($content, $marker);

        $offset = $document->offsetAt($wire['line'], $wire['character']);

        self::assertSame(
            $wire,
            $document->positionAt($offset),
            "cursor marker '$marker' must round-trip through the document boundary",
        );
    }

    /**
     * A spread of existing cursor-marker fixtures, re-run under the negotiated
     * encoding to prove the corpus that predates it still addresses the boundary
     * correctly.
     *
     * @codeCoverageIgnore
     *
     * @return iterable<string, array{string, string}>
     */
    public static function existingCursorMarkerCases(): iterable
    {
        yield 'this member access' => ['src/Completion/MethodAccess.php', 'this_prefix'];
        yield 'variable member access' => ['src/Completion/MethodAccess.php', 'var_prefix'];
        yield 'new keyword' => ['src/Completion/NewCompletion.php', 'new_abstract'];
        yield 'variable prefix' => ['src/Completion/Variables.php', 'param_prefix'];
    }

    private function assertRoundTrips(TextDocument $document, int $offset): void
    {
        $position = $document->positionAt($offset);

        self::assertSame(
            $offset,
            $document->offsetAt($position['line'], $position['character']),
            "byte offset $offset must survive a conversion to a wire position and back",
        );
    }
}
