<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * A trait's own alias is part of the trait, so a class using it has it.
 */
class TraitAliasReachesUser
{
    use AliasingTrait;
}
