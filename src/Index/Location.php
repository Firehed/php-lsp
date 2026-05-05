<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

final readonly class Location
{
    public function __construct(
        public string $uri,
        public int $startLine,
        public int $startCharacter,
        public int $endLine,
        public int $endCharacter,
    ) {
    }

    /**
     * Creates a Location from a filesystem path and 1-based line number.
     * Returns null if either argument is null.
     */
    public static function fromFileLine(?string $file, ?int $line): ?self
    {
        if ($file === null || $line === null) {
            return null;
        }
        $uri = str_starts_with($file, 'file://') ? $file : 'file://' . $file;
        return new self($uri, $line - 1, 0, $line - 1, 0);
    }

    /**
     * @return array{
     *   uri: string,
     *   range: array{
     *     start: array{line: int, character: int},
     *     end: array{line: int, character: int},
     *   },
     * }
     */
    public function toLspLocation(): array
    {
        return [
            'uri' => $this->uri,
            'range' => [
                'start' => ['line' => $this->startLine, 'character' => $this->startCharacter],
                'end' => ['line' => $this->endLine, 'character' => $this->endCharacter],
            ],
        ];
    }
}
