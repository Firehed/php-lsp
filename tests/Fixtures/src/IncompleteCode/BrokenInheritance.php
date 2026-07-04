<?php

declare(strict_types=1);

namespace Fixtures\IncompleteCode;

use Fixtures\Inheritance\ParentClass;

// Class has syntax errors that make it hard for ClassRepository to index
// But the extends clause is parseable and ParentClass exists
class BrokenChildWithParent extends ParentClass
    public function childMethod(): void
    {
    }

    public function test(): void
    {
        $this->/*|broken_inherited*/
    }
}
