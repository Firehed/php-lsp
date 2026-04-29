<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

use PhpParser\Node\Expr;

enum CompletionContext
{
    case ThisMember;
    case VariableMember;
    case StaticMember;
    case ParentMember;
    case Unknown;

    public static function fromMemberAccess(Expr $var): self
    {
        if ($var instanceof Expr\Variable && $var->name === 'this') {
            return self::ThisMember;
        }
        if ($var instanceof Expr\Variable) {
            return self::VariableMember;
        }
        return self::Unknown;
    }
}
