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
    // CompletionContext enum tests - verifying specific context types
    // =========================================================================

    public function testFullContextInNormalPhpCode(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access.php');
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInHtml(): void
    {
        $code = $this->loadFixture('ContextDetector/mixed_html_php.php');
        $htmlPos = strpos($code, '<body>');
        self::assertIsInt($htmlPos);
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, $htmlPos + 3));
    }

    public function testNoneContextInComment(): void
    {
        $code = $this->loadFixture('ContextDetector/single_line_comment.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInSingleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/single_quoted_string_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testVariablesOnlyContextInDoubleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/double_quoted_string_open.php');
        self::assertSame(CompletionContext::VariablesOnly, ContextDetector::getContext($code, strlen($code)));
    }

    public function testVariablesOnlyContextInHeredoc(): void
    {
        $code = $this->loadFixture('ContextDetector/heredoc_open.php');
        self::assertSame(CompletionContext::VariablesOnly, ContextDetector::getContext($code, strlen($code)));
    }

    public function testNoneContextInNowdoc(): void
    {
        $code = $this->loadFixture('ContextDetector/nowdoc_open.php');
        self::assertSame(CompletionContext::None, ContextDetector::getContext($code, strlen($code)));
    }

    public function testFullContextInStringInterpolationBraces(): void
    {
        $code = $this->loadFixture('ContextDetector/interpolation_braces.php');
        $pos = strpos($code, '->}');
        self::assertIsInt($pos);
        self::assertSame(CompletionContext::Full, ContextDetector::getContext($code, $pos + 2));
    }

    // =========================================================================
    // Valid completion contexts (legacy isCompletable API)
    // =========================================================================

    public function testCompletableInNormalCode(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterMemberAccess(): void
    {
        $code = $this->loadFixture('ContextDetector/chained_member_access.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInFunctionBody(): void
    {
        $code = $this->loadFixture('ContextDetector/function_body_incomplete.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInTypeHintPosition(): void
    {
        $code = $this->loadFixture('ContextDetector/type_hint_incomplete.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInUseStatement(): void
    {
        $code = $this->loadFixture('ContextDetector/use_statement_incomplete.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Invalid completion contexts - Outside PHP tags
    // =========================================================================

    public function testNotCompletableInHtml(): void
    {
        $code = $this->loadFixture('ContextDetector/mixed_html_php.php');
        $htmlPos = strpos($code, '<body>');
        self::assertIsInt($htmlPos);
        self::assertFalse(
            ContextDetector::isCompletable($code, $htmlPos + 3),
            'Completion should not be offered in HTML outside PHP tags',
        );
    }

    public function testCompletableInShortEchoTag(): void
    {
        $code = $this->loadFixture('ContextDetector/short_echo.php');
        self::assertTrue(
            ContextDetector::isCompletable($code, strlen($code)),
            'Completion should work inside <?= short echo tags',
        );
    }

    // =========================================================================
    // Invalid completion contexts - Comments
    // =========================================================================

    public function testNotCompletableInSingleLineComment(): void
    {
        $code = $this->loadFixture('ContextDetector/single_line_comment.php');
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInSingleLineHashComment(): void
    {
        $code = $this->loadFixture('ContextDetector/hash_comment.php');
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInMultiLineComment(): void
    {
        $code = $this->loadFixture('ContextDetector/multiline_comment_open.php');
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInDocblock(): void
    {
        $code = $this->loadFixture('ContextDetector/docblock_open.php');
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Invalid completion contexts - Strings
    // =========================================================================

    public function testNotCompletableInSingleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/single_quoted_string_open.php');
        self::assertFalse(
            ContextDetector::isCompletable($code, strlen($code)),
            'Single-quoted strings do not process variables',
        );
    }

    public function testCompletableInDoubleQuotedString(): void
    {
        $code = $this->loadFixture('ContextDetector/double_quoted_string_open.php');
        self::assertTrue(
            ContextDetector::isCompletable($code, strlen($code)),
            'Double-quoted strings process variables, so completion should be available',
        );
    }

    public function testCompletableInInterpolatedString(): void
    {
        $code = $this->loadFixture('ContextDetector/interpolated_string_open.php');
        self::assertTrue(
            ContextDetector::isCompletable($code, strlen($code)),
            'Interpolated strings process variables, so completion should be available',
        );
    }

    public function testCompletableInsideInterpolationBraces(): void
    {
        $code = $this->loadFixture('ContextDetector/interpolation_braces.php');
        $pos = strpos($code, '->}');
        self::assertIsInt($pos);
        self::assertTrue(ContextDetector::isCompletable($code, $pos + 2));
    }

    // =========================================================================
    // Invalid completion contexts - Heredoc/Nowdoc
    // =========================================================================

    public function testCompletableInHeredoc(): void
    {
        $code = $this->loadFixture('ContextDetector/heredoc_open.php');
        self::assertTrue(
            ContextDetector::isCompletable($code, strlen($code)),
            'Heredocs process variables, so completion should be available',
        );
    }

    public function testNotCompletableInNowdoc(): void
    {
        $code = $this->loadFixture('ContextDetector/nowdoc_open.php');
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Edge cases - Completable after closing constructs
    // =========================================================================

    public function testCompletableAfterClosedString(): void
    {
        $code = $this->loadFixture('ContextDetector/after_closed_string.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterClosedComment(): void
    {
        $code = $this->loadFixture('ContextDetector/after_closed_comment.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterClosedHeredoc(): void
    {
        $code = $this->loadFixture('ContextDetector/heredoc_closed.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Robustness tests - Broken/Invalid syntax
    // =========================================================================

    public function testReturnsCompletableForCompletelyBrokenSyntax(): void
    {
        $code = $this->loadFixture('ContextDetector/broken_function_param.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesUnclosedBrace(): void
    {
        $code = $this->loadFixture('ContextDetector/unclosed_class_method.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesUnclosedParenthesis(): void
    {
        $code = $this->loadFixture('ContextDetector/unclosed_closure.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesMidEditIncompleteStatement(): void
    {
        $code = $this->loadFixture('ContextDetector/mid_edit_statement.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesEmptyCode(): void
    {
        $code = '';
        self::assertTrue(ContextDetector::isCompletable($code, 0));
    }

    public function testHandlesCodeWithOnlyPhpTag(): void
    {
        $code = $this->loadFixture('ContextDetector/php_tag_only.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNeverThrowsException(): void
    {
        $testCases = [
            '',
            '<?php',
            '<?php $',
            '<?php "unclosed',
            "<?php 'unclosed",
            '<?php /* unclosed',
            '<?php /** unclosed',
            '<?php // comment',
            "<?php <<<EOF\nunclosed",
            '<?php function(',
            '<?php class {',
            '<?php if (true',
            "\x00\x01\x02", // Binary garbage
            str_repeat('a', 10000), // Large input
        ];

        foreach ($testCases as $code) {
            // Should never throw - if it does, the test fails
            ContextDetector::isCompletable($code, strlen($code));
        }
        // If we reached here, no exceptions were thrown - count as a success
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Position-specific tests
    // =========================================================================

    public function testPositionInMiddleOfComment(): void
    {
        $code = $this->loadFixture('ContextDetector/comment_with_member_access.php');
        $position = strpos($code, '$this->');
        self::assertIsInt($position);
        self::assertFalse(ContextDetector::isCompletable($code, $position + 7));
    }

    public function testPositionBeforeComment(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access_before_comment.php');
        $position = strpos($code, '$foo->bar');
        self::assertIsInt($position);
        self::assertTrue(ContextDetector::isCompletable($code, $position + 9));
    }

    public function testPositionAfterComment(): void
    {
        $code = $this->loadFixture('ContextDetector/member_access_after_comment.php');
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testZeroOffset(): void
    {
        $code = $this->loadFixture('ContextDetector/simple_statement.php');
        self::assertTrue(ContextDetector::isCompletable($code, 0));
    }

    public function testOffsetBeyondCodeLength(): void
    {
        $code = $this->loadFixture('ContextDetector/simple_statement.php');
        self::assertTrue(ContextDetector::isCompletable($code, 1000));
    }
}
