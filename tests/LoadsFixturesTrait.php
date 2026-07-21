<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

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
        $marker = "/*|{$cursorName}*/";
        $pos = strpos($content, $marker);
        assert($pos !== false, "Cursor marker not found: $cursorName");

        $beforeMarker = substr($content, 0, $pos);
        $lines = explode("\n", $beforeMarker);
        $line = count($lines) - 1;

        return [
            'line' => $line,
            'character' => strlen($lines[$line]),
        ];
    }
}
