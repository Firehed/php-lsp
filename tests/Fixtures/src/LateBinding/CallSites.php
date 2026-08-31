<?php

declare(strict_types=1);

namespace Fixtures\LateBinding;

class CallSites
{
    public function triggerMethodCallStatic(): void
    {
        $s = new Sub();
        $s->copyInstance(); //hover:method_call_static
    }

    public function triggerNullsafeMethodCallStatic(): void
    {
        $s = new Sub();
        $s?->copyInstance(); //hover:nullsafe_method_call_static
    }

    public function triggerStaticCallStatic(): void
    {
        Sub::makeInstance(); //hover:static_call_static
    }

    public function triggerMethodCallSelf(): void
    {
        $s = new Sub();
        $s->selfMethod(); //hover:method_call_self
    }

    public function triggerMethodCallParent(): void
    {
        $s = new Sub();
        $s->parentMethod(); //hover:method_call_parent
    }
}
