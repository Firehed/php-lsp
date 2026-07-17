<?php

declare(strict_types=1);

namespace App;

use Fixtures\Traits\HasTimestamps;

// A `use` inside a class body is a trait application, an unrelated construct that
// merely shares the keyword with an import. It must keep the ordinary
// class-reference behavior (resolving through imports/the current namespace), never
// the absolute namespace navigation an import `use` triggers (#40).
class Widget
{
    use HasT/*|trait_use*/
}
