<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use PHPUnit\Framework\Assert;

/**
 * The server has nothing to offer at this position.
 */
final readonly class NoAnswer implements DefinitionExpectationInterface
{
    public function checkDefinition(array $locations): void
    {
        Assert::assertSame([], $locations, 'there is no definition');
    }
}
