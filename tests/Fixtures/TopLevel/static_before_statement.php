<?php

declare(strict_types=1);

use Fixtures\Domain\User;

// The next statement parses onto the unfinished access, as `User::strlen('x')`.
User::/*|static_before_statement*/

strlen('x');
