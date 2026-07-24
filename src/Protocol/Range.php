<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * An LSP range: a start and end position, each a zero-based line and character
 * offset. Serializes to the `{ start, end }` shape LSP uses for textEdits,
 * locations, diagnostics, and the like, so callers never hand-build that nested
 * array.
 *
 * @phpstan-type LspRange array{
 *   start: array{line: int, character: int},
 *   end: array{line: int, character: int},
 * }
 */
final readonly class Range
{
    public function __construct(
        public int $startLine,
        public int $startCharacter,
        public int $endLine,
        public int $endCharacter,
    ) {
    }

    /**
     * A span within a single line, from $startCharacter up to $endCharacter — the
     * common case when replacing a token the user has typed.
     */
    public static function onLine(int $line, int $startCharacter, int $endCharacter): self
    {
        return new self($line, $startCharacter, $line, $endCharacter);
    }

    /**
     * The span a completion selection replaces: the typed $prefix ending at the
     * cursor's $character column. Both columns are in the negotiated encoding, so
     * the prefix is measured in that encoding's code units — never in bytes, which
     * would misplace the start past any multibyte character (RFC 1 §4.9).
     */
    public static function forPrefix(int $line, int $character, string $prefix, PositionEncoding $encoding): self
    {
        return self::onLine($line, $character - $encoding->codeUnitLength($prefix), $character);
    }

    /**
     * @return LspRange
     */
    public function toArray(): array
    {
        return [
            'start' => ['line' => $this->startLine, 'character' => $this->startCharacter],
            'end' => ['line' => $this->endLine, 'character' => $this->endCharacter],
        ];
    }
}
