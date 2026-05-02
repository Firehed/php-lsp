<?php

// Edge cases: calling methods on unknown types or calling non-existent methods.
// All of these should return null for definition lookup.

namespace Fixtures\EdgeCases;

class KnownClass
{
    public function existingMethod(): void
    {
    }
}

function callMethodOnUntypedParam($obj): void
{
    $obj->someMethod(); //hover:untyped_param
}

function callNonExistentMethod(KnownClass $obj): void
{
    $obj->nonExistentMethod(); //hover:unknown_method
}
