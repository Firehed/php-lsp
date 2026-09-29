<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

use Firehed\PhpLsp\Domain\FileUri;

final class DocumentManager implements DocumentManagerInterface
{
    /** @var array<string, TextDocument> Keyed by decoded path: one path has several valid URI spellings */
    private array $documents = [];

    public function open(string $uri, string $languageId, int $version, string $content): void
    {
        $this->documents[FileUri::toPath($uri)] = new TextDocument($uri, $languageId, $version, $content);
    }

    public function update(string $uri, string $content, int $version): void
    {
        $doc = $this->get($uri);
        if ($doc === null) {
            return;
        }

        $this->documents[FileUri::toPath($uri)] = $doc->withContent($content, $version);
    }

    public function close(string $uri): void
    {
        unset($this->documents[FileUri::toPath($uri)]);
    }

    public function get(string $uri): ?TextDocument
    {
        return $this->documents[FileUri::toPath($uri)] ?? null;
    }

    public function isOpen(string $uri): bool
    {
        return $this->get($uri) !== null;
    }
}
