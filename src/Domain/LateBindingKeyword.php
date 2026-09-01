<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

/**
 * The three PHP keywords that stand in for a class in a class-name position:
 * `self`, `static`, and `parent`. Every reader identifies one through
 * {@see self::tryFromName()} and resolves it through {@see self::resolveIn()},
 * so no other file in `src/` compares a string against these keywords.
 */
enum LateBindingKeyword: string
{
    case Self = 'self';
    case Static = 'static';
    case Parent = 'parent';

    /**
     * The keyword named by `$name`, matched case-insensitively (PHP is
     * case-insensitive for these), or null if `$name` is not one of them.
     */
    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(NameCase::Insensitive->normalize($name));
    }

    /**
     * Resolve this keyword to the concrete class name in the context of an
     * enclosing class-like node.
     *
     * `self`/`static` resolve to the enclosing class-like's name. `parent`
     * resolves to the enclosing class's extends target: only `class` may
     * extend, so this returns null when the enclosing node is an interface,
     * trait, or enum, or when the class has no extends clause. Returns null
     * with no enclosing node at all.
     *
     * @return ?class-string
     */
    public function resolveIn(
        Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $enclosing,
    ): ?string {
        if ($enclosing === null) {
            return null;
        }
        if ($this === self::Parent) {
            if (!$enclosing instanceof Stmt\Class_ || $enclosing->extends === null) {
                return null;
            }
            $extends = $enclosing->extends;
            $resolved = $extends->getAttribute('resolvedName');
            /** @var class-string */
            return $resolved instanceof Name ? $resolved->toString() : $extends->toString();
        }
        if ($enclosing->name === null) {
            return null;
        }
        /** @var class-string */
        return isset($enclosing->namespacedName)
            ? $enclosing->namespacedName->toString()
            : $enclosing->name->toString();
    }
}
