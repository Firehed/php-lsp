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
