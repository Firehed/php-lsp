<?php

declare(strict_types=1);

namespace Fixtures\SignatureHelp;

use Fixtures\Domain\User;
use Fixtures\Enum\Priority;

/**
 * Adds two numbers together.
 */
function add(int $a, int $b): int
{
    return $a + $b;
}

function greet(string $name, int $age): string
{
    return "Hello $name, age $age";
}

class Caller extends User
{
    private ?User $nullableUser;

    public static function staticAdd(int $a, int $b): int
    {
        return $a + $b;
    }

    public function triggerThisCall(): void
    {
        $this->setName(/*|this_call*/"name");
    }

    public function triggerSelfCall(): int
    {
        return self::staticAdd(/*|self_call*/1, 2);
    }

    public function triggerNullsafeProperty(): void
    {
        $this->nullableUser?->setName(/*|nullsafe_property*/"name");
    }
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

$sum = add(/*|first_param*/1, 2);
$greeting = greet("Alice", /*|second_param*/30);
$arr = [3, 1, 2];
$mapped = array_map(/*|builtin*/fn($x) => $x * 2, $arr);
$user = new User(/*|constructor*/"id", "name", "email@example.com");
$priority = Priority::fromScore(/*|static_call*/50);
$x/*|outside_call*/ = 1;
