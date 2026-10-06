<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Marker;

use Firehed\PhpLsp\Tests\LocatesMarkersTrait;

/**
 * The symbol on a line ending in a `//hover:name` marker.
 */
final readonly class SymbolMarker implements MarkerInterface
{
    use LocatesMarkersTrait;

    public function __construct(private string $name)
    {
    }

    public function locate(string $text): array
    {
        return $this->locateHoverMarker($text, $this->name);
    }
}
