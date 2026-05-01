<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ChildForSelf extends ParentForSelf
{
    public static string $ownProperty = 'child';

    public static function ownMethod(): void
    {
        self::/*|self_inherited*/
    }
}
