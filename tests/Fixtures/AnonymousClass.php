<?php

declare(strict_types=1);

namespace Fixtures;

use Fixtures\Completion\StaticAccess;

$anonymousWithSelf = new class {
    public const FOO = 'foo';

    public function triggerSelf(): string
    {
        return self::/*|self_in_anonymous*/
    }

    public function triggerThis(): void
    {
        $this->/*|this_in_anonymous*/
    }
};

$anonymousAccessingExternal = new class {
    public function triggerExternalStatic(): void
    {
        StaticAccess::/*|static_from_anonymous*/
    }
};
