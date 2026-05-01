<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class ParentInMultiFile
{
    protected string $inheritedProperty = '';

    public function inheritedMethod(): void
    {
    }
}

class ChildInMultiFile extends ParentInMultiFile
{
    private string $ownProperty = '';

    public function ownMethod(): void
    {
    }

    public function triggerThisInChild(): void
    {
        $this->/*|this_in_second_class*/
    }
}

class FirstUnrelated
{
    public const FIRST_CONST = 1;
    public string $firstProperty = '';

    public function firstMethod(): void
    {
    }
}

class SecondUnrelated
{
    public const SECOND_CONST = 2;
    public string $secondProperty = '';

    public function secondMethod(): void
    {
    }

    public function triggerThisInSecond(): void
    {
        $this->/*|this_in_unrelated_second*/
    }

    public function triggerSelfInSecond(): int
    {
        return self::/*|self_in_second_class*/
    }
}
