<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class NoParent
{
    public function test(): void
    {
        parent::/*|parent_no_parent*/
    }
}
