<?php

class GlobalClass
{
    public function greet(): string
    {
        return 'Hello from global namespace';
    }
}

function globalFunction(string $name): string
{
    return "Hello, {$name}!";
}

const GLOBAL_CONSTANT = 'global';
