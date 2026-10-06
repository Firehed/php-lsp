<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use PHPUnit\Framework\Assert;

/**
 * The definition is exactly one place: this file, at this line, and at this
 * column when one is given.
 */
final readonly class LandsOn implements DefinitionExpectationInterface
{
    /**
     * @param string $file Path relative to the project root.
     * @param positive-int $line 1-based, as the file reads.
     * @param positive-int|null $column 1-based, as the file reads.
     */
    public function __construct(
        private string $file,
        private int $line,
        private ?int $column = null,
    ) {
    }

    public function checkDefinition(array $locations): void
    {
        Assert::assertCount(1, $locations, 'the definition is exactly one place');
        $location = $locations[0];
        Assert::assertSame(
            "{$this->file}:{$this->line}",
            "{$location->file}:{$location->line}",
            "the definition lands on {$this->file}:{$this->line}",
        );
        if ($this->column !== null) {
            Assert::assertSame($this->column, $location->column, "the definition lands on column {$this->column}");
        }
    }
}
