<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ChildWithPrefix extends ParentWithMethods
{
    public function test(): void
    {
        parent::gr/*|parent_prefix*/
    }
}
