<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Inheritance\ParentClass;

class ChildWithParent extends ParentClass
{
    public function testParent(): void
    {
        // Try to trigger text-based parent:: resolution
        /*brace*/ (parent::/*|parent_incomplete*/
    }
}

// Class without extends - parent:: should return null
class ClassWithoutExtends
{
    public function testNoParent(): void
    {
        /*brace*/ (parent::/*|parent_no_extends*/
    }
}
