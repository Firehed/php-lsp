<?php

declare(strict_types=1);

namespace Fixtures\Completion;

class EditingNamedArg
{
    public function testEditingNamedArgComplete(): void
    {
        // This is valid PHP: cursor is between args in a complete call
        new ParamClass('test', /*|editing_in_complete*/age: 5);
    }

    public function testEditingNamedArgIncomplete(): void
    {
        // This is invalid PHP: cursor before orphan `: 5`
        new ParamClass('test', /*|editing_before_colon*/: 5);
    }
}
