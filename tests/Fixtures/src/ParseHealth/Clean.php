<?php

declare(strict_types=1);

namespace Fixtures\ParseHealth;

class Clean
{
    private string $name = '';

    public function test(): void
    {
        strlen(/*|this_start*/$this->/*|in_call*/name); //jtd:this_var this
    }
}
