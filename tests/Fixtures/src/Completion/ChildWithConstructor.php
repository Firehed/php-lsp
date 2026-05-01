<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ChildWithConstructor extends ParentWithConstructor
{
    public function __construct(string $name)
    {
        parent::/*|parent_access*/
    }
}
