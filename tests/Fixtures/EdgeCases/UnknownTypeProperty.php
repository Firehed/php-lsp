<?php

// Edge cases: accessing properties on unknown types.
// All of these should return null for definition lookup.

namespace Fixtures\EdgeCases;

function accessPropertyOnUntypedParam($obj): void
{
    echo $obj->someProperty; //hover:untyped_property
}
