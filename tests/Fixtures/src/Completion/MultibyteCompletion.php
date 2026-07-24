<?php

declare(strict_types=1);

namespace Fixtures\Completion;

/**
 * Completion triggered on lines carrying a multibyte character before the cursor,
 * so the UTF-16 wire column diverges from the byte column. The interior must slice
 * the text before the cursor at the byte column, not the raw wire column, or the
 * typed prefix is silently truncated (RFC 1 §4.9). "🎉" is one astral codepoint:
 * two UTF-16 code units, four bytes.
 *
 * Each incomplete statement lives in its own method (parser error recovery).
 */
class MultibyteCompletion
{
    public function getName(): string
    {
        return '';
    }

    public function variableAfterEmoji(): void
    {
        $total = 0;
        $taxRate = '🎉'; $tax/*|var_after_emoji*/
    }
}
