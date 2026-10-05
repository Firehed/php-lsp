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
    use LocatesMarkersTrait;

    /**
     * The absolute path of a fixture, for tests that need to address the file
     * itself (a URI, a locator input) rather than only its contents.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     */
    private function fixturePath(string $fixturePath): string
    {
        return __DIR__ . '/Fixtures/' . $fixturePath;
    }

    /**
     * Load fixture file contents.
     *
     * @param string $fixturePath Path relative to tests/Fixtures/
     */
    private function loadFixture(string $fixturePath): string
    {
        $content = file_get_contents($this->fixturePath($fixturePath));
        assert($content !== false, "Fixture not found: $fixturePath");
        return $content;
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
}
