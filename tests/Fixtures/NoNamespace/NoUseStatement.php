<?php

declare(strict_types=1);

class NoUseStatementTest
{
    public function testUnresolvedClass(): void
    {
        // Class not in use statements, no namespace - falls back to raw name
        new UnknownClass(/*|no_use_no_namespace*/
    }

    public function testMultiPartClassName(): void
    {
        // Multi-part class name triggers early return in resolveFromUseStatements
        new Some\Unknown\Class_(/*|multi_part_class*/
    }

    public function testWhitespaceOnlyArg(): void
    {
        // Whitespace-only arg between commas triggers empty check
        someFunc(   , /*|whitespace_arg*/
    }
}

// $this outside class - triggers no enclosing class path
$this->outsideClass(/*|this_outside_class*/
