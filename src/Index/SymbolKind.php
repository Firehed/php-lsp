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
     * The inverse of {@see nameKind}, derived from it rather than restated: two
     * hand-written tables of one mapping are how prefix search and namespace
     * enumeration come to disagree about which kind a name denotes.
     *
     * @return list<self>
     */
    public static function forNameKind(NameKind $kind): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->nameKind() === $kind,
        ));
    }

    /**
     * Four class-likes collapse to one name kind because PHP resolves them in a
     * single symbol namespace: a name is a class or an interface, never both. Null
     * for the member kinds, which name nothing an FQN can address.
     */
    public function nameKind(): ?NameKind
    {
        return match ($this) {
            self::Class_, self::Interface_, self::Trait_, self::Enum_ => NameKind::ClassLike,
            self::Function_ => NameKind::Function_,
            self::Constant => NameKind::Constant,
            self::Method, self::Property => null,
        };
    }
}
