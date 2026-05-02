<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

class Config
{
    public function get(string $key): mixed
    {
        return null;
    }
}

function getConfig(): Config
{
    return new Config();
}

function testNamespacedFunction(): void
{
    $config = getConfig();
}

function testNamespacedFunctionUsage(): void
{
    $config = getConfig();
    echo $config;
}
