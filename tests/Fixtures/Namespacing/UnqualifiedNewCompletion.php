<?php

declare(strict_types=1);

namespace App;

class Theme
{
}

class Trigger
{
    public function build(): void
    {
        new Th/*|unqualified_new*/
    }

    public function sub(): void
    {
        new Th/*|subnamespace_new*/
    }

    public function builtin(): void
    {
        new Ex/*|builtin_new*/
    }
}
