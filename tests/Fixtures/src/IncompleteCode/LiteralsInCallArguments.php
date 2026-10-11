<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

class LiteralsInCallArguments
{
    public function commaInString(): void
    {
        literalTarget(name: 'a,b'/*|comma_in_string*/
    }

    public function parenInString(): void
    {
        literalTarget(')', /*|paren_in_string*/
    }

    public function braceInString(): void
    {
        literalTarget('{', /*|brace_in_string*/
    }

    public function bracketsInInterpolatedString(string $value): void
    {
        literalTarget("{$value}, (", /*|brackets_in_interpolated_string*/
    }

    public function bracketsInHeredoc(string $value): void
    {
        literalTarget(<<<TEXT
            {$value}, (
            TEXT, /*|brackets_in_heredoc*/
    }

    public function parenInBlockComment(): void
    {
        literalTarget(/* ) */ 1, /*|paren_in_block_comment*/
    }

    public function bracketsInLineComment(): void
    {
        literalTarget(1 // , ) {
            /*|brackets_in_line_comment*/
    }

    public function unterminatedString(): void
    {
        literalTarget('a, (/*|unterminated_string*/
    }
}
