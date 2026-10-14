<?php

declare(strict_types=1);

namespace Fixtures\ParseHealth;

class EmptyParse
{
    private string $name = '';

    public function test(): void
    {
        strlen(/*|this_start*/$this->/*|this_in_if*/ //jtd:this_var this
