<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

final class TextDocument
{
    /** @var list<int> Byte offsets of line starts */
    private array $lineOffsets;

    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly int $version,
        private readonly string $content,
    ) {
        $this->lineOffsets = $this->computeLineOffsets();
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function withContent(string $content, int $version): self
    {
        return new self($this->uri, $this->languageId, $version, $content);
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

    public function offsetAt(int $line, int $character): int
    {
        if ($line < 0 || $line >= count($this->lineOffsets)) {
            return 0;
        }

        $lineStart = $this->lineOffsets[$line];
        $lineEnd = $line + 1 < count($this->lineOffsets)
            ? $this->lineOffsets[$line + 1]
            : strlen($this->content);

        return min($lineStart + $character, $lineEnd);
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
            'character' => $offset - $this->lineOffsets[$line],
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
