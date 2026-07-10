<?php

declare(strict_types=1);

namespace Fixtures\Domain;

/**
 * Interface whose short name starts with `D`, mirroring the original #298 report
 * where `implements D` wrongly offered `date_*` functions instead of interfaces.
 */
interface Describable
{
    public function describe(): string;
}
