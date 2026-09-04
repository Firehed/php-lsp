<?php

declare(strict_types=1);

namespace Fixtures\ParseHealth;

class EmptyParse
{
    private string $name = '';

    public function test(): void
    {
        strlen($this->/*|this_in_if*/
