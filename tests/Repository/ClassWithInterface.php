<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

class ClassWithInterface implements \Countable
{
    public function count(): int
    {
        return 0;
    }
}
