<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Example;

use ReflectionClass;

/**
 * Tests are not production consumers: the parity suites use reflection as the §4.7
 * oracle by design, so the test namespace is exempt from the §4.2 confinement.
 */
final class TestsImportReflection
{
    public function reflect(string $class): ReflectionClass
    {
        return new ReflectionClass($class);
    }
}
