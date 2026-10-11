<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Lexer;

use PhpToken;

final class PhpTokenLexer implements LexerInterface
{
    public function codeOnly(string $source): string
    {
        $code = '';
        // Inside an interpolated string, its embedded expressions are part of
        // the string's text, not code around it.
        $inString = false;
        foreach (PhpToken::tokenize($source) as $token) {
            $delimiter = $token->is(['"', '`', T_START_HEREDOC, T_END_HEREDOC]);
            if ($delimiter) {
                $inString = !$inString;
            }
            $blank = $delimiter
                || $inString
                || $token->is([
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_INLINE_HTML,
                ]);
            $code .= $blank ? str_repeat(' ', strlen($token->text)) : $token->text;
        }
        return $code;
    }
}
