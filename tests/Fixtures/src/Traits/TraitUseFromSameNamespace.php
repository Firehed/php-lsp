<?php

declare(strict_types=1);

namespace Fixtures\Traits;

class TraitUseFromSameNamespace
{
    use S/*|same_ns_trait*/
}
