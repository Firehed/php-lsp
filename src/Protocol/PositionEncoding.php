<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * A negotiated position encoding, per [LSP] "Basic JSON Structures"
 * (`PositionEncodingKind`) and RFC 1 §4.9.
 *
 * An LSP `Position`'s `character` is measured in code units of the negotiated
 * encoding. The interior of the server operates on byte offsets (the AST's own
 * unit), so each encoding knows how to map a `character` column within a line to
 * a byte offset and back — the conversion RFC 1 §4.9 confines to the document
 * boundary.
 *
 * UTF-16 is the only encoding the server currently negotiates; it is also the
 * [LSP] mandatory default. A further encoding is a new case plus the match arm
 * PHPStan then requires, not a rewrite of the boundary.
 */
enum PositionEncoding: string
{
    case Utf16 = 'utf-16';

    /**
     * The byte offset within `$line` at the given `character` column, measured
     * in this encoding's code units. A column past the line's end clamps to its
     * byte length; a column landing inside a multi-unit codepoint rounds up to
     * that codepoint's boundary.
     */
    public function characterToByteOffset(string $line, int $character): int
    {
        return match ($this) {
            self::Utf16 => self::utf16ToByteOffset($line, $character),
        };
    }

    /**
     * The `character` column, in this encoding's code units, at the given byte
     * offset within `$line` — the inverse of {@see characterToByteOffset()}.
     */
    public function byteToCharacterOffset(string $line, int $byteOffset): int
    {
        return match ($this) {
            self::Utf16 => self::byteOffsetToUtf16($line, $byteOffset),
        };
    }

    /**
     * A UTF-8 codepoint occupies two UTF-16 code units (a surrogate pair) iff
     * its UTF-8 encoding is four bytes long; every shorter codepoint is a single
     * BMP code unit.
     */
    private static function utf16Units(string $codepoint): int
    {
        return strlen($codepoint) === 4 ? 2 : 1;
    }

    private static function utf16ToByteOffset(string $line, int $character): int
    {
        $byteOffset = 0;
        $units = 0;

        foreach (mb_str_split($line, 1, 'UTF-8') as $codepoint) {
            if ($units >= $character) {
                break;
            }
            $units += self::utf16Units($codepoint);
            $byteOffset += strlen($codepoint);
        }

        return $byteOffset;
    }

    private static function byteOffsetToUtf16(string $line, int $byteOffset): int
    {
        $bytes = 0;
        $units = 0;

        foreach (mb_str_split($line, 1, 'UTF-8') as $codepoint) {
            if ($bytes >= $byteOffset) {
                break;
            }
            $units += self::utf16Units($codepoint);
            $bytes += strlen($codepoint);
        }

        return $units;
    }
}
