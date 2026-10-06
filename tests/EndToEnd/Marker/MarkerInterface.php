<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Marker;

/**
 * A named place in a document's text.
 */
interface MarkerInterface
{
    /**
     * @return array{line: int, character: int} `character` is a byte column.
     */
    public function locate(string $text): array;
}
