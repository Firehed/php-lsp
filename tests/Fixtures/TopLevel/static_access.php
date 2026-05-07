<?php

declare(strict_types=1);

use Fixtures\Domain\User;

User::/*|toplevel_static*/

self::cla/*|toplevel_self*/

$anon = new class {
    public function test(): void
    {
        User::/*|anon_class_static*/
    }
};
