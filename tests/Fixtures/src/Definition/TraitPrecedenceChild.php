<?php

declare(strict_types=1);

namespace Fixtures\Definition;

class TraitPrecedenceChild extends TraitPrecedenceParent
{
    use TraitPrecedenceTrait;

    public function triggerDefTraitPrecedence(): void
    {
        $this->sharedMethod(); //hover:trait_precedence
    }
}
