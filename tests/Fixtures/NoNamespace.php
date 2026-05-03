<?php

declare(strict_types=1);

class NoNamespaceClass
{
    public static function staticMethod(): void
    {
    }

    public function triggerSelf(): void
    {
        self::/*|self_no_namespace*/
    }

    public function methodWithThis(): void
    {
        $this->staticMethod();
    }
}
