<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use ArrayObject;
use Exception;
use function Fixtures\Helpers\helperFormat;

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

    public function triggerImportedFunction(): void
    {
        helperFormat('test'); //hover:imported_function
    }

    public function triggerBuiltinClassProperty(Exception $e): void
    {
        echo $e->message; //hover:builtin_class_property
    }
}
