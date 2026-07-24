<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

/**
 * A local variable's member access on a line that also carries a multibyte
 * character, so the UTF-16 wire column trails the byte column (RFC 1 §4.9).
 * "🎉" is one astral codepoint: two UTF-16 code units, four bytes. The trailing
 * "$obj->" with no member drives resolution through the text/AST variable
 * fallback rather than a clean member-access node.
 */
class MultibyteVarAccess
{
    public function getValue(): string
    {
        return '';
    }

    public function assignedVarAfterEmoji(): void
    {
        $obj = new self();
        $flag = '🎉'; $obj->/*|var_member_multibyte*/
    }
}
