<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

final class DocumentManager
{
    /** @var array<string, TextDocument> */
    private array $documents = [];

    public function open(string $uri, string $languageId, int $version, string $content): void
    {
        $this->documents[$uri] = new TextDocument($uri, $languageId, $version, $content);
    }

    public function update(string $uri, string $content, int $version): void
    {
        $doc = $this->documents[$uri] ?? null;
        if ($doc === null) {
            return;
        }

        $this->documents[$uri] = $doc->withContent($content, $version);
    }

    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    public function get(string $uri): ?TextDocument
    {
        return $this->documents[$uri] ?? null;
    }

    public function isOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }
}
