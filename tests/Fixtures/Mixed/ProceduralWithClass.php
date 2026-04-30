<?php

namespace Fixtures\Mixed;

$config = [
    'debug' => true,
    'version' => '1.0.0',
];

function getConfig(string $key): mixed
{
    global $config;
    return $config[$key] ?? null;
}

class Helper
{
    public static function formatName(string $first, string $last): string
    {
        return "{$first} {$last}";
    }
}

$helper = new Helper();
echo Helper::formatName('John', 'Doe');
