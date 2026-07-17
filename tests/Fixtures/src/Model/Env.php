<?php

declare(strict_types=1);

namespace Fixtures\Model;

/**
 * A class whose name is also a namespace prefix: `Env.php` sits beside an `Env/`
 * directory, so `use Fixtures\Model\Env;` imports this class and opens the
 * namespace holding Env\Repository (#339).
 */
class Env
{
}
