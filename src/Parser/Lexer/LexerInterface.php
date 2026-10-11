<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\Lexer;

/**
 * Lexical facts about source text, for readers of code that need not parse.
 */
interface LexerInterface
{
    /**
     * $source with every byte of its strings, comments, and inline HTML
     * replaced by a space, so a bracket, comma, or keyword found in the
     * result is code. Offsets are unchanged.
     */
    public function codeOnly(string $source): string;

    /**
     * Whether PHP reserves $word as a keyword, in any letter case, so that
     * written alone it is never a name.
     */
    public function isKeyword(string $word): bool;
}
