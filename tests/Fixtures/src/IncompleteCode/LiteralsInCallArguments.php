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

    public function bracketsInBinaryString(string $value): void
    {
        literalTarget(b"{$value}, (", /*|brackets_in_binary_string*/
    }

    public function bracketsInNestedString(array $value): void
    {
        literalTarget("{$value["}, ("]}", /*|brackets_in_nested_string*/
    }

    public function bracketsInNowdoc(): void
    {
        literalTarget(<<<'TEXT'
            {, (
            TEXT, /*|brackets_in_nowdoc*/
    }

    public function bracketsInShellString(): void
    {
        literalTarget(`echo {, (`, /*|brackets_in_shell_string*/
    }

    public function bracketsInHashComment(): void
    {
        literalTarget(1 # , ) {
            /*|brackets_in_hash_comment*/
    }

    public function bracketsInDocblock(): void
    {
        literalTarget(/** , ) { */ 1, /*|brackets_in_docblock*/
    }

    public function attributeInArgument(): void
    {
        literalTarget(#[Attribute] fn () => 1, /*|attribute_in_argument*/
    }

    public function bracketsInInlineHtml(): void
    {
        literalTarget(1, ?>, ) {<?php /*|brackets_in_inline_html*/
    }

    public function unterminatedString(): void
    {
        literalTarget('a, (/*|unterminated_string*/
    }
}
