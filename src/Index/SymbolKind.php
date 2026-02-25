<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

enum SymbolKind: int
{
    case Class_ = 5;
    case Method = 6;
    case Property = 7;
    case Function_ = 12;
    case Constant = 14;
    case Interface_ = 11;
    case Trait_ = 10; // Using Class in LSP since there's no Trait
    case Enum_ = 13;
}
