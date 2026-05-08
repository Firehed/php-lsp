<?php

declare(strict_types=1);

namespace Fixtures\TypeInference\NamespaceA;

class ConfigA
{
    public function getValueA(): string
    {
        return 'A';
    }
}

function getConfig(): ConfigA
{
    return new ConfigA();
}

namespace Fixtures\TypeInference\NamespaceB;

class ConfigB
{
    public function getValueB(): string
    {
        return 'B';
    }
}

function getConfig(): ConfigB
{
    return new ConfigB();
}

function testNamespaceBFunction(): void
{
    $config = getConfig();
    echo $config;
}
