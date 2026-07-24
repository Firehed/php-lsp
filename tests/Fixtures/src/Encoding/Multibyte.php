<?php

declare(strict_types=1);

namespace Fixtures\Encoding;

/**
 * Content mixing ASCII with multibyte codepoints of every UTF-8 width, for the
 * position round-trip corpus (RFC 1 §4.9): "é" is two bytes and one UTF-16 unit,
 * "€" and "中" are three bytes and one unit, and "😀" is four bytes and a UTF-16
 * surrogate pair (two units). The variety across several lines makes the boundary
 * conversion a bijection worth sweeping in full.
 */
class Multibyte
{
    /**
     * @return array<string, string>
     */
    public function greetings(): array
    {
        // café, naïve — accented Latin sits in the two-byte range
        return [
            'euro' => '€ 中 £',
            'party' => '😀🎉',
            'mixed' => 'aéb€c😀d',
        ];
    }
}
