<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class ParentWithoutExtends
{
    public function callParentMethod(): void
    {
        $x = parent::method();
    }
}
