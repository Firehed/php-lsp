<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\CompletionContext;
use Firehed\PhpLsp\Completion\ContextDetector;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionContext::class)]
#[CoversClass(ContextDetector::class)]
class ContextDetectorTest extends TestCase
{
    use LoadsFixturesTrait;

    // =========================================================================
    // Full context - normal PHP code
    // =========================================================================

    public function testFullContextInNormalCode(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextAfterMemberAccess(): void
    {
        $code = $this->loadFixture('ContextDetector/chained_member_access.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInFunctionBody(): void
    {
        $code = $this->loadFixture('ContextDetector/function_body_incomplete.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInTypeHintPosition(): void
    {
        $code = $this->loadFixture('ContextDetector/type_hint_incomplete.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInUseStatement(): void
    {
        $code = $this->loadFixture('ContextDetector/use_statement_incomplete.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInShortEchoTag(): void
    {
        $code = $this->loadFixture('ContextDetector/short_echo.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInsideInterpolationBraces(): void
    {
        $code = $this->loadFixture('ContextDetector/interpolation_braces.php');
        $pos = strpos($code, '->}');
        self::assertIsInt($pos);
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, $pos + 2));
    }

    public function testFullContextAfterClosedString(): void
    {
        $code = $this->loadFixture('ContextDetector/after_closed_string.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextAfterClosedComment(): void
    {
        $code = $this->loadFixture('ContextDetector/after_closed_comment.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextAfterClosedHeredoc(): void
    {
        $code = $this->loadFixture('ContextDetector/heredoc_closed.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextForEmptyCode(): void
    {
        self::assertSame(CompletionContext::Full, ContextDetector::getContext('', 0));
    }

    public function testFullContextWithOnlyPhpTag(): void
    {
        $code = $this->loadFixture('ContextDetector/php_tag_only.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextAtZeroOffset(): void
    {
        $code = $this->loadFixture('ContextDetector/simple_statement.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, 0));
    }

    public function testFullContextWithOffsetBeyondCodeLength(): void
    {
        $code = $this->loadFixture('ContextDetector/simple_statement.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, 1000));
    }

    public function testFullContextBeforeComment(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access_before_comment.php');
        $position = strpos($code, '$foo->bar');
        self::assertIsInt($position);
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, $position + 9));
    }

    public function testFullContextAfterComment(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access_after_comment.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    // =========================================================================
    // VariablesOnly context - interpolated strings (double-quoted, heredoc)
    // =========================================================================

    public function testVariablesOnlyContextInDoubleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/double_quoted_string_open.php');
        self::assertSame(CompletionContext::VariablesOnly, ContextDetector::getContext($code, strlen($code)));
    }

    public function testVariablesOnlyContextInInterpolatedString(): void
    {
        $code = $this->loadFixture('ContextDetector/interpolated_string_open.php');
        self::assertSame(CompletionContext::VariablesOnly, ContextDetector::getContext($code, strlen($code)));
    }

    public function testVariablesOnlyContextInHeredoc(): void
    {
        $code = $this->loadFixture('ContextDetector/heredoc_open.php');
        self::assertSame(CompletionContext::VariablesOnly, ContextDetector::getContext($code, strlen($code)));
    }

    // =========================================================================
    // None context - no completions
    // =========================================================================

    public function testNoneContextInHtml(): void
    {
        $code = $this->loadFixture('ContextDetector/mixed_html_php.php');
        $htmlPos = strpos($code, '<body>');
        self::assertIsInt($htmlPos);
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, $htmlPos + 3));
    }

    public function testNoneContextInSingleLineComment(): void
    {
        $code = $this->loadFixture('ContextDetector/single_line_comment.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInHashComment(): void
    {
        $code = $this->loadFixture('ContextDetector/hash_comment.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInMultiLineComment(): void
    {
        $code = $this->loadFixture('ContextDetector/multiline_comment_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInDocblock(): void
    {
        $code = $this->loadFixture('ContextDetector/docblock_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInMiddleOfComment(): void
    {
        $code = $this->loadFixture('ContextDetector/comment_with_member_access.php');
        $position = strpos($code, '$this->');
        self::assertIsInt($position);
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, $position + 7));
    }

    public function testNoneContextInSingleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/single_quoted_string_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInNowdoc(): void
    {
        $code = $this->loadFixture('ContextDetector/nowdoc_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    // =========================================================================
    // Edge cases - broken/invalid syntax returns Full (let downstream filter)
    // =========================================================================

    public function testFullContextForBrokenSyntax(): void
    {
        $code = $this->loadFixture('ContextDetector/broken_function_param.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextWithUnclosedBrace(): void
    {
        $code = $this->loadFixture('ContextDetector/unclosed_class_method.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextWithUnclosedParenthesis(): void
    {
        $code = $this->loadFixture('ContextDetector/unclosed_closure.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextMidEditIncompleteStatement(): void
    {
        $code = $this->loadFixture('ContextDetector/mid_edit_statement.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    // =========================================================================
    // Robustness - handles edge cases without throwing
    // =========================================================================

    /**
     * @return array<string, array{string}>
     * @codeCoverageIgnore
     */
    public static function edgeCaseProvider(): array
    {
        return [
            'empty string' => [''],
            'php tag only' => ['<?php'],
            'incomplete variable' => ['<?php $'],
            'unclosed double quote' => ['<?php "unclosed'],
            'unclosed single quote' => ["<?php 'unclosed"],
            'unclosed block comment' => ['<?php /* unclosed'],
            'unclosed docblock' => ['<?php /** unclosed'],
            'single line comment' => ['<?php // comment'],
            'unclosed heredoc' => ["<?php <<<EOF\nunclosed"],
            'incomplete function' => ['<?php function('],
            'incomplete class' => ['<?php class {'],
            'incomplete if' => ['<?php if (true'],
            'binary garbage' => ["\x00\x01\x02"],
            'large input' => [str_repeat('a', 10000)],
        ];
    }

    #[DataProvider('edgeCaseProvider')]
    public function testHandlesEdgeCaseWithoutThrowing(string $code): void
    {
        $context = ContextDetector::getContext($code, strlen($code));
        self::assertInstanceOf(CompletionContext::class, $context);
    }
}
