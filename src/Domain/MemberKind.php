<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * A category of class-like member.
 *
 * Member kind is an axis switched in exactly one place;
 * this enum is that place. Everything the hierarchy walk in
 * {@see \Firehed\PhpLsp\Repository\MemberResolver} needs that varies by kind is
 * answered here, so a new kind is a case in this file rather than another pair
 * of walks.
 */
enum MemberKind
{
    case Constant;

    case EnumCase;

    case Method;

    case Property;

    public function isMethod(): bool
    {
        return $this === self::Method;
    }

    /**
     * Whether PHP checks access to this kind the way it does for an object's
     * members: protected against the class that first declared the name, and
     * with a class reaching its own private member on an instance of a
     * subclass. Constants and enum cases are checked against the nearest
     * declaration alone (`zend_verify_const_access` in php-src).
     */
    public function checksAccessByFirstDeclaration(): bool
    {
        return $this === self::Method || $this === self::Property;
    }

    /**
     * Whether a class's `as` and `insteadof` rules adapt the members of this
     * kind a trait brings in: they adapt only methods.
     */
    public function takesTraitAdaptations(): bool
    {
        return $this === self::Method;
    }

    /**
     * Whether PHP binds $member, brought in by a trait, before the using class
     * inherits from its parent. A trait's abstract methods are bound after, so
     * they only fill a name nothing else provides.
     */
    public function bindsBeforeInheritance(MemberInfoInterface $member): bool
    {
        if ($this !== self::Method) {
            return true;
        }
        assert($member instanceof MethodInfo, 'a method is described by a MethodInfo');
        return !$member->isAbstract;
    }

    /**
     * Whether a subclass keeps a parent's private member of this kind: PHP
     * keeps private methods and properties, but does not inherit private
     * constants, which enum cases are.
     */
    public function inheritsPrivate(): bool
    {
        return $this->checksAccessByFirstDeclaration();
    }

    /**
     * Whether a member overriding $inherited takes $root, the inherited
     * member's prototype or else itself, as its own prototype, which decides
     * the class protected access is checked against. A private method that is
     * neither abstract nor a constructor gives none, nor does a constructor
     * whose root is not abstract; constants and enum cases have none.
     */
    public function inheritsPrototype(MemberInfoInterface $inherited, MemberInfoInterface $root): bool
    {
        if ($this === self::Property) {
            return $inherited->getVisibility() !== Visibility::Private;
        }
        if ($this !== self::Method) {
            return false;
        }
        assert($inherited instanceof MethodInfo && $root instanceof MethodInfo, 'methods are described by MethodInfo');
        if ($this->normalize($inherited->name->name) === $this->normalize('__construct')) {
            return $root->isAbstract;
        }
        return $inherited->visibility !== Visibility::Private || $inherited->isAbstract;
    }

    /**
     * Whether $member, brought in by a trait, replaces a member of its name the
     * class already has. A static property does, as the trait declares it
     * again; anything else yields.
     */
    public function traitReplacesExisting(MemberInfoInterface $member): bool
    {
        return $this === self::Property && $member->isStatic();
    }

    /**
     * For a kind {@see self::checksAccessByFirstDeclaration()} applies to:
     * whether code reaching $found by name, on an instance of a subclass of its
     * own class, uses that class's private $own instead. A private static
     * property is not used beside an instance one.
     */
    public function prefersOwnPrivate(MemberInfoInterface $own, MemberInfoInterface $found): bool
    {
        return $this === self::Method || !$own->isStatic() || $found->isStatic();
    }

    /**
     * Where a class-like holds the members of this kind, keyed by declared name.
     *
     * @return array<string, MemberInfoInterface>
     */
    public function membersOf(ClassInfo $classInfo): array
    {
        return match ($this) {
            self::Constant => $classInfo->constants,
            self::EnumCase => $classInfo->enumCases,
            self::Method => $classInfo->methods,
            self::Property => $classInfo->properties,
        };
    }

    /**
     * A member's identity within a class-like: its kind, plus its name under
     * that kind's case rule. Two members collide only when both match.
     */
    public function keyFor(string $name): string
    {
        return $this->name . ':' . $this->normalize($name);
    }

    /**
     * The name as a lookup key. PHP matches method names case-insensitively;
     * every other member kind is matched letter-for-letter.
     */
    public function normalize(string $name): string
    {
        $rule = $this === self::Method ? NameCase::Insensitive : NameCase::Sensitive;

        return $rule->normalize($name);
    }
}
