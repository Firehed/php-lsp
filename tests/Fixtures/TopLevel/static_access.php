<?php

declare(strict_types=1);

use Fixtures\Domain\User;

self::cla/*|toplevel_self*/

User::/*|toplevel_static*/

$anon = new class {
    public function test(): void
    {
        User::/*|anon_class_static*/
    }
};
