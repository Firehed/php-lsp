<?php

// A trait that uses itself and adapts one of its methods. PHP rejects it when
// linking; code being edited can still contain it.

trait SelfUsingTraitAlias
{
    use SelfUsingTraitAlias {
        run as protected;
    }

    public function run(): string
    {
        return '';
    }

    public function call(): string
    {
        return $this->run(); //hover:self_alias_call
    }
}
