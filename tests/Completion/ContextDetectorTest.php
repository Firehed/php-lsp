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
    // Class-body detection - telling a trait `use` apart from an import `use`
    // =========================================================================

    #[DataProvider('provideClassBodyScenarios')]
    public function testIsInsideClassBody(string $code, bool $expected): void
    {
        self::assertSame(
            $expected,
            ContextDetector::isInsideClassBody($code, strlen($code)),
            'The cursor position should be recognised as (not) directly inside a class-like body',
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     * @codeCoverageIgnore
     */
    public static function provideClassBodyScenarios(): iterable
    {
        // A top-level import is not in a class body — the case that must navigate.
        yield 'top-level import' => ["<?php\nnamespace App;\nuse Psr\\Log\\Lo", false];
        yield 'import after a closed class' => ["<?php\nclass A {}\nuse Ha", false];
        // A braced namespace's brace is not a class body: the `use` is still an import.
        yield 'import in a braced namespace' => ["<?php\nnamespace App {\n    use Ha", false];
        // `::class` is a constant reference, not a declaration; it opens no class body.
        yield 'class constant reference is not a declaration' => ["<?php\n\$c = Foo::class;\nuse Ha", false];

        // A `use` directly in any class-like body is a trait application, not an import.
        yield 'class body' => ["<?php\nclass A\n{\n    use Ha", true];
        yield 'interface body' => ["<?php\ninterface I\n{\n    use Ha", true];
        yield 'trait body' => ["<?php\ntrait T\n{\n    use Ha", true];
        yield 'enum body' => ["<?php\nenum E\n{\n    use Ha", true];
        yield 'anonymous class body' => ["<?php\n\$x = new class {\n    use Ha", true];

        // Inside a method the innermost scope is the method, not the class body.
        yield 'method body' => ["<?php\nclass A {\n    function m() {\n        use Ha", false];
        // Interpolation braces must be balanced so a later top-level `use` is not
        // mistaken for a trait application.
        yield 'top-level import after interpolation braces' => [
            "<?php\nclass A { function m() { echo \"{\$x->y}\"; } }\nuse Ha",
            false,
        ];
    }

    public function testIsInsideClassBodyIgnoresCodeAfterCursor(): void
    {
        // Only code up to the cursor defines its scope; a class opened further down
        // the file must not leak in and turn a top-level import into a trait `use`.
        $code = "<?php\nuse Ha\n\nclass Widget\n{\n}\n";
        $offset = strpos($code, 'Ha');
        self::assertIsInt($offset);
        self::assertFalse(
            ContextDetector::isInsideClassBody($code, $offset + 2),
            'A class declared after the cursor does not enclose it',
        );
    }

    // =========================================================================
    // Closure-use detection - telling a capture list apart from an import `use`
    // =========================================================================

    #[DataProvider('provideClosureUseScenarios')]
    public function testIsClosureUse(string $code, bool $expected): void
    {
        self::assertSame(
            $expected,
            ContextDetector::isClosureUse($code, strlen($code)),
            'The cursor position should be recognised as (not) a closure `use()` capture list',
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     * @codeCoverageIgnore
     */
    public static function provideClosureUseScenarios(): iterable
    {
        // A closure capture list is the only `use` that follows a closing `)`.
        yield 'closure use' => ["<?php\n\$f = function () use ", true];
        // The `)` may sit on an earlier line; the whole-document walk still sees it.
        yield 'closure use across lines' => ["<?php\n\$f = function ()\n    use ", true];

        // An import or trait `use` follows a statement boundary, never a `)`.
        yield 'top-level import' => ["<?php\nuse ", false];
        yield 'import with a prefix' => ["<?php\nuse Psr\\Lo", false];
        yield 'trait use in a class body' => ["<?php\nclass A {\n    use ", false];

        // Degenerate inputs must not read past the start of the token stream.
        yield 'empty document' => ['', false];
        yield 'no use keyword' => ['<?php', false];
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
