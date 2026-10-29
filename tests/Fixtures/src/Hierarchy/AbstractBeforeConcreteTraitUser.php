<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

/**
 * Of two traits providing a method, the concrete one wins over the abstract
 * one whichever is used first.
 */
class AbstractBeforeConcreteTraitUser
{
    use AbstractSharedTrait;
    use ConcreteSharedTrait;
}
