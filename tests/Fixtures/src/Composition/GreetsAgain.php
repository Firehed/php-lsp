<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait GreetsAgain
{
    public function hello(): string
    {
        return 'again';
    }
}
