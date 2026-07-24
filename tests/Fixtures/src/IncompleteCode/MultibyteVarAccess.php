<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Domain\User;

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

/**
 * A typed parameter accessed inside an incomplete `while (` on a line carrying a
 * multibyte character (RFC 1 §4.9). The incomplete control structure denies the
 * parser a clean member-access node, so resolution reaches the variable-access
 * text fallback, which slices the text before the cursor at the byte column. A
 * raw wire-column slice would truncate "$user->" past the "🎉" and fail the match.
 */
class MultibyteParamAccessInWhile
{
    public function test(User $user): void
    {
        $flag = '🎉'; while ($user->/*|var_while_multibyte*/
    }
}
