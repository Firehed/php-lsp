<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

class Sub extends Base
{
    public function parentMethod(): parent
    {
        return $this;
    }
}
