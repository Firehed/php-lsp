<?php

// Edge case: self:: and static:: used outside of a class context.
// These should gracefully return null, not crash.

self::foo(/*|sig_self_method*/); //hover:self_method
static::bar(/*|sig_static_method*/); //hover:static_method

echo self::$prop; //hover:self_property
echo static::$prop; //hover:static_property

new self/*|def_new_self*/(/*|sig_new_self*/);
new static/*|def_new_static*/(/*|sig_new_static*/);

$className = self/*|def_self_class*/::class;
