<?php

// Edge case: parent:: used in a class that does not extend anything.
// These should gracefully return null, not crash.

class NoParentClass
{
    public function testMethod(): void
    {
        parent::foo(/*|sig_parent_method*/); //hover:parent_method
    }

    public function testProperty(): void
    {
        echo parent::$prop; //hover:parent_property
    }

    public function testConstruct(): void
    {
        new parent/*|def_new_parent*/(/*|sig_new_parent*/);
    }
}
