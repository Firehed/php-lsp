<?php

declare(strict_types=1);

namespace Fixtures\Definition;

use Fixtures\Precedence\Bootstrapped;

final class AutoloadPrecedence
{
    public function build(): Bootstrapped
    {
        return new Bootstrapped(); //hover:files_entry_class
    }
}
