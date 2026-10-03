<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Watch;

final readonly class WatchedPaths
{
    /**
     * @param list<string> $roots Directories where a PHP file appearing or
     *                            disappearing, at any depth, matters
     * @param list<string> $files Files whose content matters
     */
    public function __construct(
        public array $roots = [],
        public array $files = [],
    ) {
    }

    public function with(self $other): self
    {
        return new self(
            array_values(array_unique([...$this->roots, ...$other->roots])),
            array_values(array_unique([...$this->files, ...$other->files])),
        );
    }
}
