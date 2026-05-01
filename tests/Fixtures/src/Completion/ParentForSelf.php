<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ParentForSelf
{
    public static string $inheritedProperty = 'value';
    public const INHERITED_CONST = 'const';

    public static function inheritedMethod(): void
    {
    }
}
