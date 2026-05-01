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
}
