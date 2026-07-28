<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parity;

/**
 * Serializes a captured surface output to the canonical, diff-friendly, and
 * machine-portable form stored as a golden.
 *
 * Two properties matter for a golden that is frozen once and diffed thereafter:
 * the encoding must be stable (so an unrelated re-capture produces byte-identical
 * output), and it must be portable (so an absolute path from one machine does not
 * make every other machine's run red). Ordering is the caller's responsibility —
 * this only encodes and normalizes paths.
 */
final class GoldenCodec
{
    /**
     * Encode a captured structure as pretty JSON with a trailing newline, so the
     * golden file is a normal text file that diffs line by line.
     *
     * @param array<array-key, mixed> $captured
     */
    public static function encode(array $captured): string
    {
        return json_encode(
            $captured,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Rewrite an absolute path that lives under the project root to a
     * root-relative one, so a golden captures *which* file a symbol resolves to
     * without embedding the machine it was captured on. A `file://` scheme is
     * stripped first; a path outside the root is returned unchanged.
     */
    public static function relativizePath(string $path, string $projectRoot): string
    {
        $withoutScheme = str_starts_with($path, 'file://') ? substr($path, 7) : $path;

        $prefix = rtrim($projectRoot, '/') . '/';
        if (str_starts_with($withoutScheme, $prefix)) {
            return substr($withoutScheme, strlen($prefix));
        }

        return $withoutScheme;
    }
}
