<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

final class TraitAliasSelfReturnUser
{
    use AliasedSelfReturnTrait {
        fluent as aliasedFluent;
    }
}
