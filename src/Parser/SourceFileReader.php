<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\TextDocument;

/**
 * Reads a PHP source file from a path into a {@see TextDocument} so consumers
 * that hold a path parse through the {@see SyntaxSource\SyntaxSource} interface
 * the same way an open-document caller does. Filesystem access is confined
 * here so the parser layer is the only place a source file is opened.
 */
final class SourceFileReader
{
    public function read(string $path): ?TextDocument
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            // @codeCoverageIgnoreStart
            // Guarded above; reaching here means the file changed under us
            // between the check and the read.
            throw new \LogicException("Readable file could not be read: $path");
            // @codeCoverageIgnoreEnd
        }

        return new TextDocument($path, 'php', 0, $content);
    }
}
