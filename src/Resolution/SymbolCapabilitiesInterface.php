<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\TypeInterface;

/**
 * What a class-like can do, and the members a type offers.
 */
interface SymbolCapabilitiesInterface
{
    /**
     * The members of a type that code written in $from (null: outside any
     * class) can use: methods and properties, and for static access, constants
     * and enum cases too.
     *
     * @return list<ResolvedMemberInterface>
     */
    public function getAccessibleMembers(
        TypeInterface $type,
        ?ClasslikeName $from,
        MemberFilter $filter = MemberFilter::Instance,
    ): array;

    /**
     * Check if a name resolves to a real class-like (class, interface, trait, or
     * enum). Unlike the position predicates below, this is an existence check: it
     * exists to reject catalog directory-listing phantoms (a `functions.php`
     * surfaced as a coarse class-like) before a position filter, which is
     * optimistic for unknown names, would let them through.
     */
    public function isClassLike(ClasslikeName $className): bool;

    /**
     * Check if a class can be instantiated with `new`.
     */
    public function isInstantiable(ClasslikeName $className): bool;

    /**
     * Check if a class name is valid as a type hint.
     */
    public function isValidTypeHint(ClasslikeName $className): bool;

    /**
     * Check if a class-like is an interface (e.g. valid in an `implements` list).
     */
    public function isInterface(ClasslikeName $className): bool;

    /**
     * Check if a class-like is a trait (e.g. valid after `use` in a class body).
     */
    public function isTrait(ClasslikeName $className): bool;

    /**
     * Check if a class-like can be extended by a class (e.g. valid after
     * `class X extends`). True for non-final classes, abstract included.
     */
    public function isExtendableClass(ClasslikeName $className): bool;

    /**
     * Check if a class-like can be caught (e.g. valid after `catch (`). True for
     * `Throwable` itself and for any class or interface that extends or implements
     * it, directly or transitively.
     */
    public function isThrowable(ClasslikeName $className): bool;

    /**
     * Check if a class is a PHP attribute (e.g. valid in a `#[...]` position).
     */
    public function isAttribute(ClasslikeName $className): bool;
}
