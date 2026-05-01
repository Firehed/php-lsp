<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Inheritance\ParentClass;

class SelfInherited extends ParentClass
{
    public static string $ownStaticProperty = 'child';

    public static function ownStaticMethod(): void
    {
        self::/*|self_inherited*/
    }
}
