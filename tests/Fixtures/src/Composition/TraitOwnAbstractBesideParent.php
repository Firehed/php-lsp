<?php

declare(strict_types=1);

namespace Fixtures\Composition;

/**
 * The trait resolves its method to its own abstract one, so the parent's
 * concrete method stays.
 */
class TraitOwnAbstractBesideParent extends GreetingParent
{
    use RequiresHelloOverGreets;
}
