<?php

declare(strict_types=1);

namespace Fixtures\Namespacing\Scoped {

    class ScopedClass
    {
        public function greet(): string
        {
            return 'Hello from scoped namespace';
        }
    }

    interface ScopedInterface
    {
        public function process(): void;
    }

}

namespace Fixtures\Namespacing\Scoped\Sub {

    class SubClass
    {
        public function getName(): string
        {
            return 'SubClass';
        }
    }

}
