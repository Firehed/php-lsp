<?php

declare(strict_types=1);

namespace Fixtures\MultiClass;

function notAMethod/*|function_name*/(): void
{
}

class MagicMethodClass
{
    public const NOT_A_METHOD/*|constant_name*/ = 1;

    public int $notAMethod/*|property_name*/ = 0;

    public function __construct()
    {
    }

    public function __TOSTRING(): string
    {
        return '';
    }

    public function __/*|instance*/(): void
    {
    }

    public function make(): object
    {
        return new class {
            public function __/*|anonymous*/(): void
            {
            }
        };
    }
}

class MagicMethodStatic
{
    public static function __(): void
    {
    }
}

class MagicMethodPrivate
{
    private function __(): void
    {
    }
}

interface MagicMethodInterface
{
    public function __(): void;
}

trait MagicMethodTrait
{
    function __(): void
    {
    }
}

enum MagicMethodEnum
{
    /*|enum_body*/
    public function __(): void
    {
    }
}
