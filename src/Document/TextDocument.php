<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

use Firehed\PhpLsp\Protocol\PositionEncoding;

final class TextDocument
{
    /** @var list<int> Byte offsets of line starts */
    private array $lineOffsets;

    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly int $version,
        private readonly string $content,
        private readonly PositionEncoding $encoding = PositionEncoding::Utf16,
    ) {
        $this->lineOffsets = $this->computeLineOffsets();
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function withContent(string $content, int $version): self
    {
        return new self($this->uri, $this->languageId, $version, $content, $this->encoding);
    }

    public function getLine(int $line): string
    {
        if ($line < 0 || $line >= count($this->lineOffsets)) {
            return '';
        }

        $start = $this->lineOffsets[$line];
        $end = $line + 1 < count($this->lineOffsets)
            ? $this->lineOffsets[$line + 1] - 1 // -1 to exclude newline
            : strlen($this->content);

        return substr($this->content, $start, $end - $start);
    }

    /**
     * The text on `$line` up to `$character`, sliced at the byte column the wire
     * column maps to under the negotiated encoding. Interior components that scan
     * the text before the cursor use this rather than slicing the raw wire column
     * as a byte length, which drops or keeps bytes past a multibyte character
     * (RFC 1 §4.9).
     */
    public function textBeforeCursor(int $line, int $character): string
    {
        $lineText = $this->getLine($line);

        return substr($lineText, 0, $this->encoding->characterToByteOffset($lineText, $character));
    }

    public function offsetAt(int $line, int $character): int
    {
        if ($line < 0 || $line >= count($this->lineOffsets)) {
            return 0;
        }

        // The negotiated encoding measures `character`; the interior is bytes.
        // Converting against the line content also clamps an over-long column
        // to the line's byte length, so no separate line-end clamp is needed.
        return $this->lineOffsets[$line]
            + $this->encoding->characterToByteOffset($this->getLine($line), $character);
    }

    /**
     * @return array{line: int, character: int}
     */
    public function positionAt(int $offset): array
    {
        $offset = max(0, min($offset, strlen($this->content)));

        $line = 0;
        foreach ($this->lineOffsets as $i => $lineOffset) {
            if ($lineOffset > $offset) {
                break;
            }
            $line = $i;
        }

        return [
            'line' => $line,
            'character' => $this->encoding->byteToCharacterOffset(
                $this->getLine($line),
                $offset - $this->lineOffsets[$line],
            ),
        ];
    }

    /**
     * @return list<int>
     */
    private function computeLineOffsets(): array
    {
        $offsets = [0];
        $length = strlen($this->content);

        for ($i = 0; $i < $length; $i++) {
            if ($this->content[$i] === "\n") {
                $offsets[] = $i + 1;
            }
        }

        return $offsets;
    }
}
