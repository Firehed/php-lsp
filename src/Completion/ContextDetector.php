<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use Throwable;

/**
 * Detects whether a given position in PHP code is a valid context for
 * code completion. Returns false for positions inside comments, strings,
 * heredocs, and nowdocs where completions would be inappropriate.
 */
final class ContextDetector
{
    // Token types where completions should NOT be offered
    private const NON_COMPLETABLE_TOKENS = [
        T_COMMENT,
        T_DOC_COMMENT,
        T_CONSTANT_ENCAPSED_STRING,
        T_ENCAPSED_AND_WHITESPACE,
    ];

    /**
     * Check if the given offset in the code is a valid position for completions.
     *
     * @param string $code The full PHP source code
     * @param int $offset The byte offset position in the code (0-indexed)
     * @return bool True if completions should be offered, false otherwise
     */
    public static function isCompletable(string $code, int $offset): bool
    {
        try {
            return self::detectContext($code, $offset);
        } catch (Throwable) {
            // If tokenizer fails for any reason, assume completable
            // (let later phases filter if needed)
            return true;
        }
    }

    private static function detectContext(string $code, int $offset): bool
    {
        if ($code === '') {
            return true;
        }

        // Clamp offset to valid range
        $offset = max(0, min($offset, strlen($code)));

        $tokens = token_get_all($code);

        $currentPosition = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $tokenType = $token[0];
                $tokenText = $token[1];
                $tokenLength = strlen($tokenText);

                $tokenStart = $currentPosition;
                $tokenEnd = $currentPosition + $tokenLength;

                // Check if offset falls within this token
                // Use > for start because cursor at tokenStart is "before" the token
                if ($offset > $tokenStart && $offset <= $tokenEnd) {
                    if (in_array($tokenType, self::NON_COMPLETABLE_TOKENS, true)) {
                        return false;
                    }

                    // Check for heredoc/nowdoc content
                    if (self::isInsideHeredoc($tokenType, $offset, $tokenStart, $tokenEnd, $tokens, $currentPosition)) {
                        return false;
                    }
                }

                $currentPosition += $tokenLength;
            } else {
                // Single character token
                $currentPosition += strlen($token);
            }
        }

        // Check if we're in an unclosed string or comment at end of file
        if ($offset >= $currentPosition) {
            return self::checkUnfinishedContext($tokens);
        }

        return true;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isInsideHeredoc(
        int $tokenType,
        int $offset,
        int $tokenStart,
        int $tokenEnd,
        array $tokens,
        int $currentPosition,
    ): bool {
        // T_START_HEREDOC starts a heredoc/nowdoc
        // The actual content would be in following tokens
        if ($tokenType === T_START_HEREDOC) {
            // We're on the heredoc start line itself, which is completable
            return false;
        }

        // Check if we're in heredoc content by looking back for T_START_HEREDOC
        // without seeing T_END_HEREDOC
        return self::isInHeredocContent($tokens, $currentPosition);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isInHeredocContent(array $tokens, int $upToPosition): bool
    {
        $inHeredoc = false;
        $pos = 0;

        foreach ($tokens as $token) {
            if ($pos >= $upToPosition) {
                break;
            }

            if (is_array($token)) {
                if ($token[0] === T_START_HEREDOC) {
                    $inHeredoc = true;
                } elseif ($token[0] === T_END_HEREDOC) {
                    $inHeredoc = false;
                }
                $pos += strlen($token[1]);
            } else {
                $pos += strlen($token);
            }
        }

        return $inHeredoc;
    }

    /**
     * Check if the last token indicates we're in an unclosed context.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function checkUnfinishedContext(array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $lastToken = end($tokens);

        if (is_array($lastToken)) {
            $tokenType = $lastToken[0];

            // If last token is a non-completable type and isn't properly closed
            if (in_array($tokenType, self::NON_COMPLETABLE_TOKENS, true)) {
                return false;
            }

            // Unclosed heredoc
            if ($tokenType === T_START_HEREDOC) {
                return false;
            }
        }

        return true;
    }
}
