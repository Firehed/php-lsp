<?php

declare(strict_types=1);

namespace Fixtures\Resolution;

class ThisTypingParity
{
    public string $name = '';

    public function hoverOnThis(): void
    {
        $this;
    }

    public function completeMember(): void
    {
        $this->/*|this_member*/;
    }
}
