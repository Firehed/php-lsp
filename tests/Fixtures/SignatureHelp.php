<?php

declare(strict_types=1);

use Fixtures\Domain\User;
use Fixtures\Enum\Priority;

/**
 * Adds two numbers together.
 */
function signatureHelpAdd(int $a, int $b): int
{
    return $a + $b;
}

function useTypedUser(User $user): void
{
    $user->setName(/*|typed_param*/"name"); //hover:typedVarMethod
}

function useAssignedUser(): void
{
    $user = new User("id", "name", "email@example.com");
    $user->setName(/*|assigned_var*/"new name"); //hover:assignedVarMethod
}

function useNullsafeParam(?User $user): void
{
    $user?->setName(/*|nullsafe_param*/"name"); //hover:nullsafeTypedVar
}

$sum = signatureHelpAdd(/*|first_param*/1, 2);
$sumHover = signatureHelpAdd(1, 2); //hover:signatureHelpAdd
$greeting = signatureHelpAdd(1, /*|second_param*/2);
$mapped = array_map(/*|builtin*/fn($x) => $x * 2, [1, 2, 3]);
$user = new User(/*|constructor*/"id", "name", "email@example.com"); //hover:class_instantiation
$priority = Priority::fromScore(/*|static_call*/50);
$x/*|outside_call*/ = 1;
$namedArgs = signatureHelpAdd(a: 1, b: /*|named_arg*/2);
$undefinedNamedArg = undefinedFunction(badArg: 1);
$wrongNamedArg = signatureHelpAdd(wrongName: 1, b: 2);
$undefined = undefinedFunction(/*|undefined_func*/1);
// Edge cases for self/parent outside class
$selfCall = self::method(/*|self_outside_class*/1);
$newSelf = new self(/*|new_self_outside*/1);
$selfConst = self::CONST; //hover:self_const_outside
$selfProp = self::$prop; //hover:self_prop_outside

// Class without explicit constructor
class NoConstructor {}
$noConst = new NoConstructor(/*|no_constructor*/);
