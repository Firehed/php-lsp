<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class InheritanceChild extends \Exception
{
    private string $ownProperty = '';

    public function ownMethod(): void
    {
    }

    public function triggerThisInherited(): void
    {
        $this->/*|this_inherited*/
    }
}
