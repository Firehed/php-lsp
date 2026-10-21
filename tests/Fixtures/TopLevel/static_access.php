<?php

declare(strict_types=1);

use Fixtures\Domain\User;

$anon = new class {
    public function test(): void
    {
        User::/*|anon_class_static*/
    }
};
