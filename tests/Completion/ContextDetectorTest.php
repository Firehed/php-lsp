<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Completion;

use Firehed\PhpLsp\Completion\ContextDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextDetector::class)]
class ContextDetectorTest extends TestCase
{
    // =========================================================================
    // Valid completion contexts
    // =========================================================================

    public function testCompletableInNormalCode(): void
    {
        $code = '<?php $this->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterMemberAccess(): void
    {
        $code = '<?php $foo->bar->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInFunctionBody(): void
    {
        $code = <<<'PHP'
<?php
function test(): void
{
    $x = new
PHP;
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInTypeHintPosition(): void
    {
        $code = '<?php function test(str';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInUseStatement(): void
    {
        $code = '<?php use Psr\Log\\';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Invalid completion contexts - Comments
    // =========================================================================

    public function testNotCompletableInSingleLineComment(): void
    {
        $code = '<?php // this is a comment $this->';
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInSingleLineHashComment(): void
    {
        $code = '<?php # hash comment $this->';
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInMultiLineComment(): void
    {
        $code = <<<'PHP'
<?php
/*
 * Comment $this->
PHP;
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInDocblock(): void
    {
        $code = <<<'PHP'
<?php
/**
 * @param string $var
PHP;
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Invalid completion contexts - Strings
    // =========================================================================

    public function testNotCompletableInSingleQuotedString(): void
    {
        $code = "<?php \$x = 'hello \$this->";
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInDoubleQuotedString(): void
    {
        $code = '<?php $x = "hello ';
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInInterpolatedString(): void
    {
        // Inside the string content portion
        $code = '<?php $x = "hello {$name} world ';
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableInsideInterpolationBraces(): void
    {
        // Inside {$...} blocks, completions should work (variable interpolation)
        $code = '<?php $x = "Hello {$user->}";';
        $pos = strpos($code, '->}');
        self::assertIsInt($pos);
        // Position right after -> (before the closing })
        self::assertTrue(ContextDetector::isCompletable($code, $pos + 2));
    }

    // =========================================================================
    // Invalid completion contexts - Heredoc/Nowdoc
    // =========================================================================

    public function testNotCompletableInHeredoc(): void
    {
        $code = <<<'PHP'
<?php
$x = <<<HTML
<div>Some content
PHP;
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testNotCompletableInNowdoc(): void
    {
        $code = <<<'PHP'
<?php
$x = <<<'TEXT'
Some text content
PHP;
        self::assertFalse(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Edge cases - Completable after closing constructs
    // =========================================================================

    public function testCompletableAfterClosedString(): void
    {
        $code = '<?php $x = "hello"; $this->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterClosedComment(): void
    {
        $code = '<?php /* comment */ $this->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testCompletableAfterClosedHeredoc(): void
    {
        $code = <<<'PHP'
<?php
$x = <<<HTML
content
HTML;
$this->
PHP;
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    // =========================================================================
    // Robustness tests - Broken/Invalid syntax
    // =========================================================================

    public function testReturnsCompletableForCompletelyBrokenSyntax(): void
    {
        // Assume completable (fallback) for completely broken code
        $code = '<?php function foo( $';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesUnclosedBrace(): void
    {
        $code = '<?php class Foo { public function bar() { $this->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesUnclosedParenthesis(): void
    {
        $code = '<?php $result = array_map(function($x) { return $x->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesMidEditIncompleteStatement(): void
    {
        $code = '<?php $foo = $bar->';
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testHandlesEmptyCode(): void
    {
        $code = '';
        self::assertTrue(ContextDetector::isCompletable($code, 0));
    }

    public function testHandlesCodeWithOnlyPhpTag(): void
    {
        $code = '<?php ';
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
        $code = '<?php // comment $this-> more';
        $position = strpos($code, '$this->');
        self::assertIsInt($position);
        self::assertFalse(ContextDetector::isCompletable($code, $position + 7));
    }

    public function testPositionBeforeComment(): void
    {
        $code = '<?php $foo->bar // comment';
        $position = strpos($code, '$foo->bar');
        self::assertIsInt($position);
        self::assertTrue(ContextDetector::isCompletable($code, $position + 9));
    }

    public function testPositionAfterComment(): void
    {
        $code = "<?php // comment\n\$this->";
        self::assertTrue(ContextDetector::isCompletable($code, strlen($code)));
    }

    public function testZeroOffset(): void
    {
        $code = '<?php $x = 1;';
        self::assertTrue(ContextDetector::isCompletable($code, 0));
    }

    public function testOffsetBeyondCodeLength(): void
    {
        $code = '<?php $x = 1;';
        // Should handle gracefully
        self::assertTrue(ContextDetector::isCompletable($code, 1000));
    }
}
