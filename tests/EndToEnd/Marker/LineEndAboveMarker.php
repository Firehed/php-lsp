<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Marker;

use Firehed\PhpLsp\Tests\LocatesMarkersTrait;

/**
 * The end of the line above a named cursor marker: a position no marker can
 * occupy when that line ends in a `//` comment, since the marker would become
 * part of the comment.
 */
final readonly class LineEndAboveMarker implements MarkerInterface
{
    use LocatesMarkersTrait;

    public function __construct(private string $name)
    {
    }

    public function locate(string $text): array
    {
        $line = $this->locateCursor($text, $this->name)['line'] - 1;

        return ['line' => $line, 'character' => strlen(explode("\n", $text)[$line])];
    }
}
