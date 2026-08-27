<?php

declare(strict_types=1);

class TopLevelClass
{
    public string $name = '';

    public function greet(): string
    {
        return 'hello ' . $this->name;
    }
}
