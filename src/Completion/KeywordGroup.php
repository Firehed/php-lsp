<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * A named set of PHP keywords valid in a particular completion position.
 */
enum KeywordGroup
{
    /** Every keyword — valid at the start of a statement/expression */
    case All;

    /** Keywords valid directly inside a class body */
    case ClassBody;

    /** Keywords valid immediately after a visibility modifier */
    case AfterVisibility;

    /** Keywords valid at the start of an expression (e.g. a named-argument value) */
    case Expression;
}
