<?php

namespace Fixtures\Mixed;

use Fixtures\Completion\MethodAccess;
use Fixtures\Completion\StaticAccess;

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

function processMethodAccess(MethodAccess $obj): void
{
    $obj->/*|standalone_function_access*/
}

function triggerStaticAccess(): void
{
    StaticAccess::/*|standalone_static_access*/
}

function triggerVarFromStaticCall(): void
{
    $obj = StaticAccess::create();
    $obj->/*|var_from_static_call*/
}

function triggerVarFromStaticCallNullsafe(): void
{
    $obj = StaticAccess::create();
    $obj?->/*|var_from_static_call_nullsafe*/
}

function triggerUnknownVar(): void
{
    $unknown->/*|unknown_var*/
}

function triggerDynamicVar(): void
{
    $$dynamic->/*|dynamic_var*/
}

function triggerDynamicStatic(): void
{
    $class = 'DateTime';
    $class::/*|dynamic_static*/
}
