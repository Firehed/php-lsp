<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

/**
 * Converts between the `file://` URIs LSP identifies documents by and the
 * filesystem paths the autoload maps and the parser work in.
 *
 * A URI percent-encodes reserved characters and a path does not, so the two
 * directions are not string concatenation: skipping the decode makes a path
 * containing a space or `#` silently wrong, and only shows up on the machines whose
 * checkout happens to sit under such a directory.
 */
final class FileUri
{
    private const string SCHEME = 'file://';

    /**
     * A path that is not a `file://` URI is returned unchanged: callers hold
     * identifiers from several sources and a bare path must not be mangled.
     */
    public static function toPath(string $uri): string
    {
        if (!str_starts_with($uri, self::SCHEME)) {
            return $uri;
        }

        return rawurldecode(substr($uri, strlen(self::SCHEME)));
    }

    public static function fromPath(string $path): string
    {
        // Directory separators are structure rather than data, so they are restored
        // after encoding rather than escaped into %2F.
        return self::SCHEME . str_replace('%2F', '/', rawurlencode($path));
    }
}
