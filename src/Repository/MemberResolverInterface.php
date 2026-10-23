<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;

/**
 * The single seam every question about the type graph goes through — member
 * lookup and subclass checks alike. Callers ask for a member of a class; the
 * implementation walks used traits, then the parent chain, then interfaces,
 * so no member kind can see a different hierarchy than another.
 *
 * A member lookup takes `$from`: the class-like the code asking is written
 * in, or null for code outside any class. Only a member PHP lets that code
 * use is found.
 *
 * A member comes back with `static`, `self`, and `parent` in its types bound:
 * `self` to the class declaring it, `parent` to that class's parent, and
 * `static` to $class, or to `$static` when given — code calling `parent::`
 * passes its own class, as PHP does.
 */
interface MemberResolverInterface
{
    public function findConstant(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
        ?ClasslikeName $static = null,
    ): ?ClasslikeConstantInfo;

    public function findEnumCase(ClasslikeName $class, string $name): ?EnumCaseInfo;

    public function findMethod(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
        ?ClasslikeName $static = null,
    ): ?MethodInfo;

    public function findProperty(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
        ?ClasslikeName $static = null,
    ): ?PropertyInfo;

    /**
     * @return list<MemberInfoInterface>
     */
    public function getMembersOfKind(
        ClasslikeName $class,
        MemberKind $kind,
        ?ClasslikeName $from,
        MemberFilter $filter = MemberFilter::All,
        ?ClasslikeName $static = null,
    ): array;

    /**
     * Whether $class is a subtype of $potentialParent somewhere along the type
     * graph. Not reflexive, and — matching PHP's `is_subclass_of` — a class is
     * never a subclass of a trait it uses.
     */
    public function isSubclassOf(ClasslikeName $class, ClasslikeName $potentialParent): bool;

    public function isInterface(ClasslikeName $class): bool;

    public function isTrait(ClasslikeName $class): bool;
}
