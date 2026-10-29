<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * Of two traits providing a method, the concrete one wins over the abstract
 * one, whichever is used first.
 */
class AbstractBeforeConcreteTrait
{
    use RequiresHello;
    use Greets;
}
