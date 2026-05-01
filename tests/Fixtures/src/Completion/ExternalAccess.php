<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ExternalAccess
{
    public function accessMethodAccess(MethodAccess $obj): void
    {
        $obj->/*|external_method_access*/
    }
}
