<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

/**
 * What code that stops mid-construct needs for php-parser to read it: a
 * stand-in word where an operand must follow, the closing quote of an
 * unterminated string, and the closers of every bracket still open.
 */
trait FinishesCodeTrait
{
    /**
     * Tokens after which an operand is complete, or none is needed.
     */
    private const array ENDS_OPERAND = [
        T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE,
        T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, ')', ']', '}', ';', '{', '"',
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_OPEN_TAG,
    ];

    /**
     * The token a bracket-opening token is closed by.
     */
    private const array CLOSERS = [
        '(' => ')',
        '[' => ']',
        '{' => '}',
        T_ATTRIBUTE => ']',
        T_CURLY_OPEN => '}',
        T_DOLLAR_OPEN_CURLY_BRACES => '}',
    ];

    /**
     * Everything the code needs to be read: its unfinished operand or string
     * ended, then every open bracket closed.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function finish(array $tokens): string
    {
        [$standIn, $open, $previous] = self::unfinished($tokens);
        return $standIn . self::terminated($open, $previous);
    }

    /**
     * The text that ends the code's last unfinished operand or string, the
     * closers of the brackets still open (innermost last), and the text the
     * code ends with once the operand is ended.
     *
     * @param list<\PhpToken> $tokens
     * @return array{string, list<string>, ?string}
     */
    private static function unfinished(array $tokens): array
    {
        $open = [];
        $inString = false;
        $last = null;
        foreach ($tokens as $token) {
            if ($token->is('"')) {
                $inString = !$inString;
            }
            $closer = self::CLOSERS[$token->id] ?? self::CLOSERS[$token->text] ?? null;
            if ($closer !== null) {
                $open[] = $closer;
            } elseif ($token->is([')', ']', '}'])) {
                array_pop($open);
            }
            if (!$token->isIgnorable() && $token->id !== 0) {
                $last = $token;
            }
        }
        $standIn = match (true) {
            $inString => '"',
            $last === null, $last->is(self::ENDS_OPERAND) => '',
            $last->is(T_ENCAPSED_AND_WHITESPACE) && str_starts_with($last->text, "'") => "'",
            $last->is(T_NS_SEPARATOR) => '_',
            default => ' _',
        };
        return [$standIn, $open, $standIn === '' ? $last?->text : $standIn];
    }

    /**
     * The closers, innermost first, with the statement before the first
     * closing brace ended.
     *
     * @param list<string> $open
     */
    private static function terminated(array $open, ?string $previous): string
    {
        $ending = '';
        foreach (array_reverse($open) as $closer) {
            if ($closer === '}' && $previous !== '}' && $previous !== '{' && $previous !== ';') {
                $ending .= ';';
            }
            $ending .= $closer;
            $previous = $closer;
        }
        return $ending;
    }
}
