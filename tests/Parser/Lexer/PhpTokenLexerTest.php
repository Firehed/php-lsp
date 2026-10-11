<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\Lexer;

use Firehed\PhpLsp\Parser\Lexer\PhpTokenLexer;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpTokenLexer::class)]
final class PhpTokenLexerTest extends TestCase
{
    use LoadsFixturesTrait;

    private const string LITERALS = 'src/IncompleteCode/LiteralsInCallArguments.php';

    /**
     * @return array<string, array{string, string}>
     */
    public static function callsBeforeMarkers(): array
    {
        return [
            'a single-quoted string' => ['comma_in_string', 'literalTarget(name: '],
            'an interpolated string' => ['brackets_in_interpolated_string', 'literalTarget( , '],
            'a heredoc' => ['brackets_in_heredoc', 'literalTarget( , '],
            'a block comment' => ['paren_in_block_comment', 'literalTarget( 1, '],
            'a line comment' => ['brackets_in_line_comment', 'literalTarget(1 '],
            'an unterminated string' => ['unterminated_string', 'literalTarget( '],
        ];
    }

    #[DataProvider('callsBeforeMarkers')]
    public function testStringsAndCommentsAreBlanked(string $marker, string $code): void
    {
        $content = $this->loadFixture(self::LITERALS);
        $source = substr($content, 0, $this->markerOffset($content, $marker));
        $call = strrpos($source, 'literalTarget(');
        self::assertIsInt($call);

        self::assertSame(
            $code,
            self::collapseSpace(substr((new PhpTokenLexer())->codeOnly($source), $call)),
            'only the code of the call remains',
        );
    }

    public function testInlineHtmlIsBlanked(): void
    {
        $content = $this->loadFixture('ContextDetector/mixed_html_php.php');

        self::assertSame(
            ' <?php echo $foo; ?> ',
            self::collapseSpace((new PhpTokenLexer())->codeOnly($content)),
            'only the text between PHP tags remains',
        );
    }

    public function testOffsetsAreUnchanged(): void
    {
        $content = $this->loadFixture(self::LITERALS);

        self::assertSame(
            strlen($content),
            strlen((new PhpTokenLexer())->codeOnly($content)),
            'every blanked byte becomes exactly one byte',
        );
    }

    private static function collapseSpace(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', $text);
        self::assertIsString($collapsed);

        return $collapsed;
    }
}
