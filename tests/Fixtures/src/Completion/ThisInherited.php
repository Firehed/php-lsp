<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Inheritance\ChildClass;

class ThisInherited extends ChildClass
{
    private string $ownProperty = '';

    public function ownMethod(): void
    {
    }

    public function triggerThis(): void
    {
        $this->/*|this_inherited*/
    }
}
