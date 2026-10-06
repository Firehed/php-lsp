<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use Firehed\PhpLsp\Tests\EndToEnd\Session\Session;
use PHPUnit\Framework\Assert;
use stdClass;

/**
 * A place in a project file, as a person reads it: a path relative to the
 * project and a 1-based line.
 */
final readonly class Location
{
    public function __construct(
        public string $file,
        public int $line,
    ) {
    }

    /**
     * Decodes an [LSP] Location, whose lines are 0-based.
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

        return new self($session->fileOf($uri), $line + 1);
    }
}
