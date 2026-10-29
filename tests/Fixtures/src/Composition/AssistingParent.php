<?php

declare(strict_types=1);

namespace Fixtures\Composition;

class AssistingParent
{
    public function assist(): string
    {
        return 'parent';
    }
}
