<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\NameKind;

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

    /**
     * The index kinds that belong to a knowledge kind. The single home for this
     * mapping — backends call this rather than branching on kind themselves.
     *
     * @return list<self>
     */
    public static function forNameKind(NameKind $kind): array
    {
        return match ($kind) {
            NameKind::ClassLike => [self::Class_, self::Interface_, self::Trait_, self::Enum_],
            NameKind::Constant => [self::Constant],
            NameKind::Function_ => [self::Function_],
        };
    }
}
