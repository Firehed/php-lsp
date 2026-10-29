<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait Greets
{
    public function hello(): string
    {
        return 'hello';
    }
}
