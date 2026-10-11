<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Lexer;

use PhpToken;

final class PhpTokenLexer implements LexerInterface
{
    private const array TEXT_TOKENS = [
        T_CONSTANT_ENCAPSED_STRING => true,
        T_ENCAPSED_AND_WHITESPACE => true,
        T_COMMENT => true,
        T_DOC_COMMENT => true,
        T_INLINE_HTML => true,
    ];

    public function codeOnly(string $source): string
    {
        $code = '';
        // What each enclosing construct opened: true for a string, false for
        // an interpolation inside one. Everything inside the outermost string,
        // its interpolated expressions included, is its text.
        $open = [];
        foreach (PhpToken::tokenize($source) as $token) {
            $inString = $open !== [];
            // Ids, not text: a binary string opens with the two bytes `b"`.
            switch ($token->id) {
                case T_START_HEREDOC:
                    $open[] = true;
                    break;
                case T_END_HEREDOC:
                    array_pop($open);
                    break;
                case ord('"'):
                case ord('`'):
                    if (end($open) === true) {
                        array_pop($open);
                    } else {
                        $open[] = true;
                    }
                    break;
                case T_CURLY_OPEN:
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $open[] = false;
                    break;
                case ord('}'):
                    if (end($open) === false) {
                        array_pop($open);
                    }
                    break;
            }
            $blank = $inString || $open !== [] || isset(self::TEXT_TOKENS[$token->id]);
            $code .= $blank ? str_repeat(' ', strlen($token->text)) : $token->text;
        }
        return $code;
    }

    public function isKeyword(string $word): bool
    {
        return preg_match('/\A[a-z_][a-z0-9_]*\z/i', $word) === 1
            && PhpToken::tokenize('<?php ' . $word)[1]->id !== T_STRING;
    }
}
