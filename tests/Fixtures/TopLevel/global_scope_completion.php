<?php

declare(strict_types=1);

use Fixtures\Domain\User;

// Procedural code at file scope (no namespace): variable completion and
// member completion on a typed variable should both work here.
$currentUser = new User('1', 'Ada', 'ada@example.com');
$loginCount = 5;

$currentUser->/*|global_member_access*/
