<?php
namespace App\Services;

class MyService
{
    public function doSomething(): void
    {
        // InternalClass is not imported but should resolve to App\Services\InternalClass
        InternalClass::/*|unimported_static*/
    }
}
