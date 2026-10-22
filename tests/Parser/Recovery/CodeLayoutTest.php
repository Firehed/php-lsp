<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\Recovery;

use Firehed\PhpLsp\Parser\Recovery\CodeLayout;
use Firehed\PhpLsp\Parser\SyntaxSource\PhpParserCodeParser;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeLayout::class)]
final class CodeLayoutTest extends TestCase
{
    use LoadsFixturesTrait;

    public function testTheFileBodyStartsAfterTheOpeningTag(): void
    {
        [$code, $layout] = $this->layout('Recovery/OnlyOpenTag.php');

        self::assertSame([strlen('<?php' . "\n"), strlen($code)], $layout->fileBody());
    }

    public function testABracedBodyIsTheInsideOfItsBraces(): void
    {
        [$code, $layout] = $this->layout('Recovery/UnbracedIf.php');
        $open = (int) strpos($code, '{');

        self::assertSame(
            [$open + 1, (int) strrpos($code, '}')],
            $layout->bracedBody((int) strpos($code, '$count)') + 5, true),
            'parameters and a return type may sit between a function header and its brace',
        );
    }

    public function testAHeaderNotFollowedByABraceHasNoBracedBody(): void
    {
        [$code, $layout] = $this->layout('Recovery/UnbracedIf.php');

        self::assertNull(
            $layout->bracedBody((int) strpos($code, '$count) echo') + 5, false),
            'a statement written without braces is not a body to read gaps in',
        );
    }

    public function testABodyThatNeverClosesRunsToTheEnd(): void
    {
        [$code, $layout] = $this->layout('Recovery/EndsInMemberAccess.php');
        $open = (int) strpos($code, '{', (int) strpos($code, 'run()'));

        self::assertSame([$open + 1, strlen($code)], $layout->bracedBody($open - 1, true));
    }

    public function testANamespaceDeclarationsBodyRunsToTheGivenEnd(): void
    {
        [$code, $layout] = $this->layout('Recovery/DroppedInStatementNamespace.php');
        $name = (int) strpos($code, 'Fixtures\\Recovery');
        $semicolon = (int) strpos($code, ';', $name);

        self::assertSame([$semicolon + 1, 40 + $semicolon], $layout->statementBody($semicolon - 1, 40 + $semicolon));
        self::assertNull(
            $layout->statementBody($semicolon, strlen($code)),
            'a header not followed by its semicolon has no statement body',
        );
    }

    public function testARegionSplitsWhereTheParserReportedAnError(): void
    {
        [$code, $layout] = $this->layout('Recovery/DroppedInStatementNamespace.php');
        $start = (int) strpos($code, '$dropped');
        $error = (int) strpos($code, ';', $start);

        self::assertSame(
            [[$start, $error], [$error, $error + 1]],
            $layout->piecesOf($start, $error + 1),
            'each part holds code on its own side of the error',
        );
        self::assertSame(
            [],
            $layout->piecesOf($start + strlen('$dropped = '), $error),
            'a part with no code in it is not a piece',
        );
    }

    public function testClosingBracketsAloneAreNotAPiece(): void
    {
        [$code, $layout] = $this->layout('Recovery/UnbracedIf.php');
        $close = (int) strrpos($code, '}');

        self::assertSame([], $layout->piecesOf($close, $close + 1), 'a closing brace cannot read as anything');
    }

    public function testLinesAndTextAreReadFromTheCode(): void
    {
        [$code, $layout] = $this->layout('Recovery/UnbracedIf.php');
        $if = (int) strpos($code, 'if (');

        self::assertSame(7, $layout->lineOf($if));
        self::assertSame('if (', $layout->text($if, $if + 4));
    }

    /**
     * @return array{string, CodeLayout}
     */
    private function layout(string $fixture): array
    {
        $code = $this->loadFixture($fixture);
        $attempt = (new PhpParserCodeParser())->parse($code);
        return [$code, new CodeLayout($code, $attempt->tokens, $attempt->errors, false)];
    }
}
