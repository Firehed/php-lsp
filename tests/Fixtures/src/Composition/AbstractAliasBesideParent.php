<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * An alias of an abstract trait method is bound after inheritance, so the
 * parent's method of the alias's name stays.
 */
class AbstractAliasBesideParent extends AssistingParent
{
    use RequiresHelper {
        helper as assist;
    }

    public function helper(): string
    {
        return 'own';
    }
}
