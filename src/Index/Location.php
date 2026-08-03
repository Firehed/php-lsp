<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Document\FileUri;

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
        // This URI goes to the client, and an unencoded space or `#` in it is not a
        // valid URI, so it is built through the one conversion seam rather than by
        // concatenating. Decoding first makes the factory total over both spellings:
        // today's callers pass a path, and one that passed a URI would otherwise get
        // it encoded a second time.
        $uri = FileUri::fromPath(FileUri::toPath($file));
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
