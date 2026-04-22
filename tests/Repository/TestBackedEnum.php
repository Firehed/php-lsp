<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Repository;

enum TestBackedEnum: int
{
    case Low = 1;
    case High = 10;
}
