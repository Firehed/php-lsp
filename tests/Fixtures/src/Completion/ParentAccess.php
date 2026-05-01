<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Inheritance\ParentClass;

class ParentAccess extends ParentClass
{
    public function __construct(string $name)
    {
        parent::/*|parent_access*/
    }
}
