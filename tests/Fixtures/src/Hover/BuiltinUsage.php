<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use ArrayObject;
use Exception;

class BuiltinUsage
{
    public function triggerBuiltinFunction(): void
    {
        $arr = [3, 1, 2];
        sort($arr); //hover:builtin_function
    }

    public function triggerBuiltinClassMethod(ArrayObject $obj): void
    {
        $obj->getArrayCopy(); //hover:builtin_class_method
    }

    public function triggerBuiltinClassProperty(Exception $e): void
    {
        echo $e->message; //hover:builtin_class_property
    }
}
