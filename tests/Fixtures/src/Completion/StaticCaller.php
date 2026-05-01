<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class StaticCaller
{
    public function triggerExternalStatic(): void
    {
        StaticAccess::/*|external_static*/
    }

    public function triggerConstAccess(): void
    {
        StaticAccess::NAME/*|const_access*/
    }
}
