<?php

declare(strict_types=1);

use Fixtures\Domain\User;

// Complete (parseable) procedural code at file scope: hover and go-to-definition
// on a member call, and hover on the variable itself, should work here.
$activeUser = new User('2', 'Bob', 'bob@example.com');

$activeUser->getName(); //hover:global_method_call

$activeUser; //hover:global_var
