<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Document\DocumentSourceInterface;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\FileUri;

/**
 * Reads a PHP source file from disk into a {@see TextDocument}. Filesystem
 * access is confined here so this is the only place a source file is opened.
 */
final class SourceFileReader implements DocumentSourceInterface
{
    public function read(string $uri): ?TextDocument
    {
        $path = FileUri::toPath($uri);
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

        return new TextDocument($uri, 'php', 0, $content);
    }
}
