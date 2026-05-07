<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Utility\AbstractBase;
use Fixtures\Utility\SealedClass;

class NewCompletion
{
    public function newAbstract(): void
    {
        $x = new /*|new_abstract*/
    }
}
