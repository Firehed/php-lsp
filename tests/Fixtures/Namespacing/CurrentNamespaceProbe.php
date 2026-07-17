<?php

declare(strict_types=1);

namespace Fixtures\Model;

/**
 * A file whose own namespace is Fixtures\Model, where `Env` is a child namespace
 * (not a `use` import). `new Env\R` resolves relative to the current namespace and
 * reaches Fixtures\Model\Env\Repository (#339).
 */
class CurrentNamespaceProbe
{
    public function make(): void
    {
        new Env\R/*|current_ns_child*/
    }
}
