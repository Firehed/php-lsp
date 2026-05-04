<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * Represents a person with a name.
 */
interface Person
{
    /**
     * Gets the person's name.
     */
    public function getName(): string;

    public function getAge(): int;
}

function usePersonInterface(Person $p): void
{
    $p->getName(); //hover:interface_method
}
