<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Hierarchy\AliasedSelfReturnTrait;

/**
 * A `self`-return method exposed under a trait `use ... as` alias.
 * The call site triggers ExpressionResolver::resolveLateBoundReturn
 * with a MethodInfo whose `aliasedFrom` names the trait, so the trait
 * subject test on that field decides late-static rebinding.
 */
final class TraitAliasSelfReturnCall
{
    use AliasedSelfReturnTrait {
        fluent as aliasedFluent;
    }

    public function trigger(): void
    {
        $this->aliasedFluent(); //hover:aliased_call
    }
}
