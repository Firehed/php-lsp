<?php

declare(strict_types=1);

namespace App;

// A `use` import names its symbol absolutely (from the global namespace), so the
// file's own `App` namespace must not affect what is offered here (#40). Each
// incomplete `use` is navigated from the catalog, not the current document's AST.
use Ps/*|use_first_segment*/
use Fixtures\Domain\/*|use_workspace_class*/
