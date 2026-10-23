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

    public function parentConstant(): void
    {
        parent::NONE; //hover:parent_constant_static
    }
}
