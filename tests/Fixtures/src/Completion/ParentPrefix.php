<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Inheritance\ParentClass;

class ParentPrefix extends ParentClass
{
    public function test(): void
    {
        parent::p/*|parent_prefix*/
    }
}
