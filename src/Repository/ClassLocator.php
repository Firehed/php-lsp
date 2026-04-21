<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassName;

/**
 * Locates the file path for a given class name.
 */
interface ClassLocator
{
    /**
     * @return ?string Absolute file path where the class is defined, or null if not found
     */
    public function locate(ClassName $name): ?string;
}
