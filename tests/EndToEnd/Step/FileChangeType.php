<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Step;

/**
 * [LSP] workspace/didChangeWatchedFiles, FileChangeType.
 */
enum FileChangeType: int
{
    case Created = 1;
    case Changed = 2;
    case Deleted = 3;
}
