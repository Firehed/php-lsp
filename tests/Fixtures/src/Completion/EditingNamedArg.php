<?php

declare(strict_types=1);

namespace Fixtures\Completion;

function localHelper(string $name, int $count = 0): void
{
}

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

    public function testStaticMethodComplete(): void
    {
        // Static method call with complete named arg
        NamedArguments::staticWithParams('test', /*|static_in_complete*/limit: 5);
    }

    public function testStaticMethodIncomplete(): void
    {
        // Static method call with incomplete named arg
        NamedArguments::staticWithParams('test', /*|static_before_colon*/: 5);
    }

    public function testStaticMethodEmpty(): void
    {
        // Static method call with cursor right after open paren
        NamedArguments::staticWithParams(/*|static_empty*/
    }

    public function testInstanceMethodEmpty(): void
    {
        // Instance method call with cursor right after open paren
        $this->instanceMethod(/*|instance_empty*/
    }

    public function testFunctionCallEmpty(): void
    {
        // Function call with cursor right after open paren
        localHelper(/*|function_empty*/
    }

    private function instanceMethod(string $name, int $count = 0): void
    {
    }
}
