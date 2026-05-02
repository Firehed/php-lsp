<?php

declare(strict_types=1);

class GlobalConfig
{
    public function get(): string
    {
        return '';
    }
}

function getGlobalConfig(): GlobalConfig
{
    return new GlobalConfig();
}

function testGlobalFunction(): void
{
    $config = getGlobalConfig();
}
