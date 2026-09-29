<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Filesystem;

final readonly class DirectoryListing
{
    /**
     * @param list<string> $files Full paths of the PHP files directly inside
     * @param list<string> $directories Full paths of the directories directly inside
     */
    public function __construct(
        public string $path,
        public array $files,
        public array $directories,
    ) {
    }
}
