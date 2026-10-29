<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * A trait's own method wins over one from a trait it uses, even when its own
 * is abstract.
 */
trait AbstractOverConflictingTraitA
{
    use ConflictingTraitA;

    abstract public function conflictMethod(): string;
}
