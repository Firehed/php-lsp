<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Recovery;

use Firehed\PhpLsp\Parser\SyntaxSource\CodeParserInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;
use PhpParser\Token;

/**
 * Code typed at the end of a file usually stops mid-construct, and php-parser
 * reads nothing when it reaches the end of the file still recovering from an
 * error. This {@see RecoveryInterface} finishes the code with what would close
 * it — a stand-in word where an operand must follow, the closing quote of an
 * unterminated string, and the closers of every open bracket — and reads it
 * again. The added text sits past the end of the code, so every position in
 * the code is unchanged.
 */
final class EndOfFileRecovery implements RecoveryInterface
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

    public function __construct(
        private readonly CodeParserInterface $parser,
    ) {
    }

    public function recover(string $code, ParseAttempt $attempt): ParseAttempt
    {
        if ($attempt->tree !== [] || $attempt->errors === []) {
            return $attempt;
        }
        $finished = $this->parser->parse($code . self::ending($attempt->tokens));
        return $finished->tree === [] ? $attempt : $finished;
    }

    /**
     * @param list<Token> $tokens
     */
    private static function ending(array $tokens): string
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

        $ending = match (true) {
            $inString => '"',
            $last === null, $last->is(self::ENDS_OPERAND) => '',
            $last->is(T_ENCAPSED_AND_WHITESPACE) && str_starts_with($last->text, "'") => "'",
            default => ' _',
        };
        $previous = $ending === '' ? $last?->text : $ending;
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
