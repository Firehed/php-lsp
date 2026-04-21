<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * Kind of class-like declaration.
 */
enum ClassKind
{
    case Class_;
    case Interface_;
    case Trait_;
    case Enum_;
}
