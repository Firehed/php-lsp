<?php

declare(strict_types=1);

namespace Fixtures\Domain;

class CurrentNamespaceBareProbe
{
    public function triggerBare(): void
    {
        $x = Us/*|current_ns_bare*/
    }
}
