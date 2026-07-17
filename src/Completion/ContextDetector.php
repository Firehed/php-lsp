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

    /**
     * Whether $offset sits directly inside a class-like body (class, interface,
     * trait, or enum), where a `use` is a trait application rather than a namespace
     * import — two unrelated constructs that share the keyword. Token-based so it
     * survives mid-edit breakage, like the rest of this detector, and structural so
     * it can tell the two `use` forms apart, which the single line the classifier
     * sees cannot.
     */
    public static function isInsideClassBody(string $code, int $offset): bool
    {
        // One entry per open brace, true when that brace opened a class-like body.
        // The innermost (last) entry is the scope the cursor sits directly in.
        $braceOpensClassBody = [];
        $nextBraceOpensClassBody = false;
        $previousSignificant = null;

        foreach (self::significantTokensBefore($code, $offset) as $token) {
            // Interpolation braces (`"{$x}"`, `"${x}"`) open a scope closed by a
            // plain `}`, so they are tracked to keep the brace stack balanced.
            if ($token === T_CURLY_OPEN || $token === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braceOpensClassBody[] = false;
                $nextBraceOpensClassBody = false;
            } elseif (
                // A class-like declaration marks the next `{` as a class body. The
                // `::class` constant reference is not a declaration and is excluded.
                ($token === T_CLASS || $token === T_INTERFACE || $token === T_TRAIT || $token === T_ENUM)
                && $previousSignificant !== T_DOUBLE_COLON
            ) {
                $nextBraceOpensClassBody = true;
            } elseif ($token === '{') {
                $braceOpensClassBody[] = $nextBraceOpensClassBody;
                $nextBraceOpensClassBody = false;
            } elseif ($token === '}') {
                array_pop($braceOpensClassBody);
            }

            $previousSignificant = $token;
        }

        return $braceOpensClassBody !== [] && end($braceOpensClassBody) === true;
    }

    /**
     * Whether $offset sits in a closure's `use (...)` capture list, where a `use`
     * captures variables from the enclosing scope rather than importing a namespace
     * — again two unrelated constructs that share the keyword. The capture list is
     * the only place a `use` follows a `)` (a closing parameter list); an import or
     * trait `use` follows a statement boundary. Token-based and whole-document, so
     * it survives mid-edit breakage and sees a `)` on an earlier line.
     */
    public static function isClosureUse(string $code, int $offset): bool
    {
        // `) use` — the closure's parameter list closes, then the capture keyword.
        return array_slice(self::significantTokensBefore($code, $offset), -2) === [')', T_USE];
    }

    /**
     * The significant tokens (whitespace, comments, and docblocks removed) that
     * begin before $offset, each reduced to its token id (array tokens) or literal
     * text (single-character tokens). Only code up to the cursor defines its scope,
     * so scanning stops there. Shared by the structural `use`-disambiguation checks
     * so they walk the document identically.
     *
     * @return list<int|string>
     */
    private static function significantTokensBefore(string $code, int $offset): array
    {
        $significant = [];
        $position = 0;

        foreach (token_get_all($code) as $token) {
            if ($position >= $offset) {
                break;
            }

            if (is_array($token)) {
                $position += strlen($token[1]);
                $id = $token[0];
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $significant[] = $id;
                continue;
            }

            $position += strlen($token);
            $significant[] = $token;
        }

        return $significant;
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
