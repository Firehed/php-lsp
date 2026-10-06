<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

/**
 * [LSP] workspace/didChangeWatchedFiles, FileEvent.
 */
final readonly class FileChange
{
    /**
     * @param string $file Path relative to the project root.
     */
    public function __construct(
        public string $file,
        public FileChangeType $type,
    ) {
    }
}
