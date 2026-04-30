<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * Represents a person with a name.
 */
interface Person
{
    public function getName(): string;

    public function getAge(): int;
}
