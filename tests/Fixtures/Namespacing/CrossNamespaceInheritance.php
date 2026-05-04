<?php

declare(strict_types=1);

namespace Base {

    class ParentClass
    {
        /**
         * Method from Base namespace.
         */
        public function baseMethod(): void
        {
        }
    }

}

namespace App {

    use Base\ParentClass;

    class ChildClass extends ParentClass
    {
        public function triggerCrossNamespaceHover(): void
        {
            $this->baseMethod(); //hover:cross_namespace_method
        }
    }

}
