<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

class Sub extends Base
{
    public function parentMethod(): parent
    {
        return $this;
    }

    public static function triggerParentCallStatic(): void
    {
        parent::makeInstance(); //hover:parent_call_static
    }

    public static function triggerParentConstant(): void
    {
        parent::NAME; //hover:parent_constant_static
    }
}
