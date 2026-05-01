<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Fixtures\Inheritance\ChildClass;

class InheritanceCompletion extends ChildClass
{
    private string $ownProperty = '';

    public function ownMethod(): void
    {
    }

    public static string $ownStaticProperty = 'own';

    public static function ownStaticMethod(): void
    {
        self::/*|self_inherited*/
    }

    public function triggerThis(): void
    {
        $this->/*|this_inherited*/
    }

    public function __construct(string $name)
    {
        parent::/*|parent_access*/
    }

    public function triggerParentPrefix(): void
    {
        parent::p/*|parent_prefix*/
    }
}
