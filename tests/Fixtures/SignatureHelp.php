<?php

declare(strict_types=1);

use Fixtures\Domain\User;
use Fixtures\Enum\Priority;

function signatureHelpAdd(int $a, int $b): int
{
    return $a + $b;
}

function useTypedUser(User $user): void
{
    $user->setName(/*|typed_param*/"name");
}

function useAssignedUser(): void
{
    $user = new User("id", "name", "email@example.com");
    $user->setName(/*|assigned_var*/"new name");
}

function useNullsafeParam(?User $user): void
{
    $user?->setName(/*|nullsafe_param*/"name");
}

$sum = signatureHelpAdd(/*|first_param*/1, 2);
$greeting = signatureHelpAdd(1, /*|second_param*/2);
$mapped = array_map(/*|builtin*/fn($x) => $x * 2, [1, 2, 3]);
$user = new User(/*|constructor*/"id", "name", "email@example.com");
$priority = Priority::fromScore(/*|static_call*/50);
$x/*|outside_call*/ = 1;
