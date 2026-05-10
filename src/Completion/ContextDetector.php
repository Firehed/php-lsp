<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

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
        if ($code === '') {
            return CompletionContext::Full;
        }

        $codeLength = strlen($code);
        $offset = max(0, min($offset, $codeLength));
        $tokens = token_get_all($code);
        $currentPosition = 0;
        $inNowdoc = false;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $tokenType = $token[0];
                $tokenText = $token[1];
                $tokenLength = strlen($tokenText);

                // Track heredoc vs nowdoc (nowdoc has quotes: <<<'IDENT')
                if ($tokenType === T_START_HEREDOC) {
                    $inNowdoc = str_contains($tokenText, "'");
                } elseif ($tokenType === T_END_HEREDOC) {
                    $inNowdoc = false;
                }

                $tokenEnd = $currentPosition + $tokenLength;
                // Cursor is inside if: after token start AND before token end
                // Exception: at EOF, cursor at token end is considered inside (unclosed construct)
                $isInside = $offset > $currentPosition
                    && ($offset < $tokenEnd || ($offset === $tokenEnd && $offset === $codeLength));

                if ($isInside) {
                    return self::contextForToken($tokenType, $tokenText, $inNowdoc);
                }

                $currentPosition += $tokenLength;
            } else {
                // Single-character tokens (operators, delimiters) - completion works after them
                $currentPosition += strlen($token);
            }
        }

        // Cursor is at or past end of all tokens - normal PHP context
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
}
