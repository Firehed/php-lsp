<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * Base interface for all domain entities.
 */
interface Entity
{
    public function getId(): string;
}
