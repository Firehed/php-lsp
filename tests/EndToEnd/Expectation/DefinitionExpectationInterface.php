<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Expectation;

use Firehed\PhpLsp\Tests\EndToEnd\Result\Location;

/**
 * Something a definition answer must satisfy. A failure is a PHPUnit
 * assertion failure.
 */
interface DefinitionExpectationInterface
{
    /**
     * @param list<Location> $locations Empty when the server has no answer.
     */
    public function checkDefinition(array $locations): void;
}
