<?php

declare(strict_types=1);

namespace App\Model\User;

/**
 * Symbols living in the namespace that the `App\Model\User` class name also
 * opens. Reached from a file that does `use App\Model\User;`, `Repository` is
 * writable as `User\Repository`. `Contract` shares that namespace but is an
 * interface, so it must still be excluded from an instantiable (`new`) position.
 */
class Repository
{
}

interface Contract
{
}
