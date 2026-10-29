<?php

declare(strict_types=1);

namespace Fixtures\Composition;

trait AliasingTrait
{
    use Greets {
        hello as wave;
    }
}
