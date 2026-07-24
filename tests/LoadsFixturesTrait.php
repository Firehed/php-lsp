<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Protocol\PositionEncoding;

/**
 * Provides fixture file loading for unit tests.
 *
 * Use this for tests that need fixture file contents without the full
 * handler infrastructure (document manager, sync handler, etc.).
 *
 * For handler tests, use OpensDocumentsTrait instead.
 */
trait LoadsFixturesTrait
{
    /**
     * Load fixture file contents.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     */
    private function loadFixture(string $fixturePath): string
    {
        $fullPath = __DIR__ . '/Fixtures/' . $fixturePath;
        $content = file_get_contents($fullPath);
        assert($content !== false, "Fixture not found: $fixturePath");
        return $content;
    }

    /**
     * Resolves a fixture cursor marker to the position immediately before it.
     *
     * Markers use the pattern SLASH*|marker_name*SLASH (where SLASH is /).
     * This is the position math alone, with no document opened, so tests that
     * drive the server over the wire can address a marker too.
     *
     * @param string $cursorName The marker name (without delimiters)
     * @return array{line: int, character: int}
     */
    private function locateCursor(string $content, string $cursorName): array
    {
        ['line' => $line, 'before' => $before] = $this->splitAtCursor($content, $cursorName);

        return [
            'line' => $line,
            'character' => strlen($before),
        ];
    }

    /**
     * Resolves a fixture cursor marker to its position with `character` as the
     * negotiated-encoding (UTF-16) wire column — what a conformant client sends.
     * {@see locateCursor()} returns a byte column, which coincides with the wire
     * column only for ASCII; a fixture with multibyte content before the cursor
     * needs the true wire column to exercise the boundary conversion the interior
     * relies on (RFC 1 §4.9).
     *
     * @param string $cursorName The marker name (without delimiters)
     * @return array{line: int, character: int}
     */
    private function locateCursorUtf16(string $content, string $cursorName): array
    {
        ['line' => $line, 'before' => $before] = $this->splitAtCursor($content, $cursorName);

        return [
            'line' => $line,
            'character' => PositionEncoding::Utf16->codeUnitLength($before),
        ];
    }

    /**
     * The line the cursor marker sits on and the text preceding it on that line,
     * shared by the byte- and wire-column marker resolvers.
     *
     * @param string $cursorName The marker name (without delimiters)
     * @return array{line: int, before: string}
     */
    private function splitAtCursor(string $content, string $cursorName): array
    {
        $marker = "/*|{$cursorName}*/";
        $pos = strpos($content, $marker);
        assert($pos !== false, "Cursor marker not found: $cursorName");

        $beforeMarker = substr($content, 0, $pos);
        $lines = explode("\n", $beforeMarker);
        $line = count($lines) - 1;

        return [
            'line' => $line,
            'before' => $lines[$line],
        ];
    }
}
