<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

enum CompletionContext
{
    /** No completions should be offered */
    case None;

    /** Only variable completions ($foo) should be offered */
    case VariablesOnly;

    /** All completions should be offered */
    case Full;
}
