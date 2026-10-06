<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Marker;

use Firehed\PhpLsp\Tests\LocatesMarkersTrait;

/**
 * The last `$var` on a line ending in a `//jtd:name var` marker.
 */
final readonly class VariableMarker implements MarkerInterface
{
    use LocatesMarkersTrait;

    public function __construct(private string $name)
    {
    }

    public function locate(string $text): array
    {
        return $this->locateVariableMarker($text, $this->name);
    }
}
