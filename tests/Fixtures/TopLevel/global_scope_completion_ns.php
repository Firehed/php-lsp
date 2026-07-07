<?php

declare(strict_types=1);

namespace App\Legacy;

use Fixtures\Domain\User;

// Procedural code inside a (braceless) namespace: the file-level statements
// live under a Namespace_ node rather than at the AST root.
$currentUser = new User('1', 'Ada', 'ada@example.com');

$currentUser->/*|global_member_access_ns*/
