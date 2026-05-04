<?php

declare(strict_types=1);

namespace Fixtures\Utility;

$anonymousInstance = new class {
    public function methodWithThis(): void
    {
        $this->methodWithThis();
    }
};
