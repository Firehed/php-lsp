<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Marker;

use Firehed\PhpLsp\Tests\LocatesMarkersTrait;

/**
 * The position just before a named cursor marker (CONTRIBUTING.md, "Cursor
 * markers"): where a person's cursor sits while typing.
 */
final readonly class CursorMarker implements MarkerInterface
{
    use LocatesMarkersTrait;

    public function __construct(private string $name)
    {
    }

    public function locate(string $text): array
    {
        return $this->locateCursor($text, $this->name);
    }
}
