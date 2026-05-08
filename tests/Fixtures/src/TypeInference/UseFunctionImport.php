<?php

declare(strict_types=1);

namespace Fixtures\TypeInference\ImportSource;

class ImportedConfig
{
    public function getImportedValue(): string
    {
        return 'imported';
    }
}

function getConfig(): ImportedConfig
{
    return new ImportedConfig();
}

namespace Fixtures\TypeInference\ShadowedFunction;

use function Fixtures\TypeInference\ImportSource\getConfig;

class LocalConfig
{
    public function getLocalValue(): string
    {
        return 'local';
    }
}

function getConfig(): LocalConfig
{
    return new LocalConfig();
}

class ShadowedFunctionTest
{
    public function testImportedShadowsLocal(): void
    {
        $config = getConfig();
        echo $config;
    }

    public function testFqnCallsLocal(): void
    {
        $config = \Fixtures\TypeInference\ShadowedFunction\getConfig();
        echo $config;
    }

    public function testNamespaceKeywordCallsLocal(): void
    {
        $config = namespace\getConfig();
        echo $config;
    }
}
