<?php

declare(strict_types=1);

namespace Fixtures\TypeInference\BracketedA {

    class BracketedConfigA
    {
        public function getValueA(): string
        {
            return 'A';
        }
    }

    function getConfig(): BracketedConfigA
    {
        return new BracketedConfigA();
    }
}

namespace Fixtures\TypeInference\BracketedB {

    class BracketedConfigB
    {
        public function getValueB(): string
        {
            return 'B';
        }
    }

    function getConfig(): BracketedConfigB
    {
        return new BracketedConfigB();
    }

    function testBracketedNamespaceBFunction(): void
    {
        $config = getConfig();
        echo $config;
    }
}
