<?php

declare(strict_types=1);

class GlobalConfig
{
    public function get(): string
    {
        return '';
    }

    public function doSomething(): void
    {
        $this->get();
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
