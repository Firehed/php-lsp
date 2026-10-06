<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * A place in a project file, as a person reads it: a path relative to the
 * project, a 1-based line, and a 1-based column.
 */
final readonly class Location
{
    /**
     * @param int $column Counted in UTF-16 code units, as the wire counts them.
     */
    public function __construct(
        public string $file,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * Decodes the start of an [LSP] Location, whose positions are 0-based.
     */
    public static function fromWire(mixed $wire, Session $session): self
    {
        Assert::assertInstanceOf(stdClass::class, $wire, 'a Location is an object ([LSP] Location)');
        $uri = $wire->uri ?? null;
        Assert::assertIsString($uri, 'a Location has a uri');
        $range = $wire->range ?? null;
        Assert::assertInstanceOf(stdClass::class, $range, 'a Location has a range');
        $start = $range->start ?? null;
        Assert::assertInstanceOf(stdClass::class, $start, 'a Range has a start');
        $line = $start->line ?? null;
        Assert::assertIsInt($line, 'a Position has a line');
        $character = $start->character ?? null;
        Assert::assertIsInt($character, 'a Position has a character');

        return new self($session->fileOf($uri), $line + 1, $character + 1);
    }
}
