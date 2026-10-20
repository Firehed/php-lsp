<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class NextLineParsedOnto
{
    public function trigger(): void
    {
        $this->/*|this_before_statement*/

        strlen('x');
    }
}
