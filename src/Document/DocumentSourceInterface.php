<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Document;

interface DocumentSourceInterface
{
    /**
     * The document's current content, or null when there is none to read.
     *
     * @param string $uri A `file://` URI or a filesystem path
     */
    public function read(string $uri): ?TextDocument;
}
