<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\Location;
use PHPUnit\Framework\Assert;

/**
 * The definition is exactly one place: this file, at this line.
 */
final readonly class LandsOn implements DefinitionExpectationInterface
{
    /**
     * @param string $file Path relative to the project root.
     * @param positive-int $line 1-based, as the file reads.
     */
    public function __construct(
        private string $file,
        private int $line,
    ) {
    }

    public function checkDefinition(array $locations): void
    {
        Assert::assertEquals(
            [new Location($this->file, $this->line)],
            $locations,
            "the definition lands on {$this->file}:{$this->line}",
        );
    }
}
