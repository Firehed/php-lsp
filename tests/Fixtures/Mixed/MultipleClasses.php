<?php

declare(strict_types=1);

namespace Fixtures\Mixed;

class First
{
    public function method(): string
    {
        return 'first';
    }
}

class Second
{
    private First $first;

    public function __construct(First $first)
    {
        $this->first = $first;
    }

    public function method(): string
    {
        return $this->first->method() . ' second';
    }
}

interface ThirdInterface
{
    public function process(): void;
}

trait ThirdTrait
{
    public function traitMethod(): bool
    {
        return true;
    }
}

class Third implements ThirdInterface
{
    use ThirdTrait;

    public function process(): void
    {
    }
}
