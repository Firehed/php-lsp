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
     * The index kinds that belong to a knowledge kind. Four class-likes collapse to
     * one because PHP resolves them in a single symbol namespace: a name is a class
     * or an interface, never both.
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

    /**
     * The inverse of {@see forNameKind}, derived from it rather than restated: two
     * hand-written tables of one mapping are how prefix search and namespace
     * enumeration come to disagree about which kind a name denotes. Null for the
     * member kinds, which name nothing an FQN can address.
     */
    public function nameKind(): ?NameKind
    {
        foreach (NameKind::cases() as $kind) {
            if (in_array($this, self::forNameKind($kind), true)) {
                return $kind;
            }
        }

        return null;
    }
}
