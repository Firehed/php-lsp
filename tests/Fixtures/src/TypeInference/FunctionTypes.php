<?php

declare(strict_types=1);

namespace Fixtures\TypeInference;

use Fixtures\Domain\User;

function getUser(): User
{
    return new User('1', 'Test', 'test@example.com');
}

function testNamespacedFunction(): void
{
    $user = getUser();
}

function testNamespacedFunctionUsage(): void
{
    $user = getUser();
    echo $user;
}
