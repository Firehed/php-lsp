<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Throwable;

/**
 * Detects what kind of completions are appropriate at a given position in PHP code.
 */
final class ContextDetector
{
    // Token types where no completions should be offered
    private const NO_COMPLETION_TOKENS = [
        T_COMMENT,
        T_DOC_COMMENT,
        T_CONSTANT_ENCAPSED_STRING,
        T_INLINE_HTML,
    ];

    /**
     * Determine what kind of completions are appropriate at the given offset.
     *
     * @param string $code The full PHP source code
     * @param int $offset The byte offset position in the code (0-indexed)
     */
    public static function getContext(string $code, int $offset): CompletionContext
    {
        try {
            return self::detectContext($code, $offset);
        } catch (Throwable) {
            // If tokenizer fails for any reason, assume full completions
            return CompletionContext::Full;
        }
    }

    /**
     * @deprecated Use getContext() instead
     */
    public static function isCompletable(string $code, int $offset): bool
    {
        return self::getContext($code, $offset) !== CompletionContext::None;
    }

    private static function detectContext(string $code, int $offset): CompletionContext
    {
        if ($code === '') {
            return CompletionContext::Full;
        }

        $offset = max(0, min($offset, strlen($code)));
        $tokens = token_get_all($code);
        $currentPosition = 0;
        $inNowdoc = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $tokenType = $token[0];
                $tokenText = $token[1];
                $tokenLength = strlen($tokenText);
                $tokenStart = $currentPosition;
                $tokenEnd = $currentPosition + $tokenLength;

                // Track heredoc vs nowdoc (nowdoc has quotes: <<<'IDENT')
                if ($tokenType === T_START_HEREDOC) {
                    $inNowdoc = str_contains($tokenText, "'");
                } elseif ($tokenType === T_END_HEREDOC) {
                    $inNowdoc = false;
                }

                // Check if offset falls within this token
                if ($offset > $tokenStart && $offset <= $tokenEnd) {
                    return self::contextForToken($tokenType, $tokenText, $inNowdoc);
                }

                $currentPosition += $tokenLength;
            } else {
                $currentPosition += strlen($token);
            }
        }

        // Cursor is at/past end of file - check last token
        if ($offset >= $currentPosition) {
            return self::checkUnfinishedContext($tokens);
        }

        return CompletionContext::Full;
    }

    private static function contextForToken(int $tokenType, string $tokenText, bool $inNowdoc): CompletionContext
    {
        if (in_array($tokenType, self::NO_COMPLETION_TOKENS, true)) {
            return CompletionContext::None;
        }

        // T_ENCAPSED_AND_WHITESPACE is text inside interpolated strings.
        // For unclosed strings, the token includes the opening quote.
        // - Starts with single quote: unclosed single-quoted string (no variables)
        // - In nowdoc context: no variables
        // - Otherwise (double-quoted/heredoc): variables work
        if ($tokenType === T_ENCAPSED_AND_WHITESPACE) {
            if ($inNowdoc || str_starts_with($tokenText, "'")) {
                return CompletionContext::None;
            }
            return CompletionContext::VariablesOnly;
        }

        return CompletionContext::Full;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function checkUnfinishedContext(array $tokens): CompletionContext
    {
        if ($tokens === []) {
            return CompletionContext::Full;
        }

        $lastToken = end($tokens);
        $inNowdoc = false;

        // Check if we're in a nowdoc by scanning for T_START_HEREDOC
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_START_HEREDOC) {
                    $inNowdoc = str_contains($token[1], "'");
                } elseif ($token[0] === T_END_HEREDOC) {
                    $inNowdoc = false;
                }
            }
        }

        if (is_array($lastToken)) {
            return self::contextForToken($lastToken[0], $lastToken[1], $inNowdoc);
        }

        return CompletionContext::Full;
    }
}
