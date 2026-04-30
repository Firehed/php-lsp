<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Handler;

trait LoadsFixturesTrait
{
    use OpensDocumentsTrait;

    private function openFixture(string $fixturePath): string
    {
        $fullPath = dirname(__DIR__) . '/Fixtures/' . $fixturePath;
        $content = file_get_contents($fullPath);
        assert($content !== false, "Fixture not found: $fixturePath");

        $uri = 'file:///fixtures/' . $fixturePath;
        $this->openDocument($uri, $content);

        return $uri;
    }
}
