<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\Visibility;

/**
 * The single seam every question about the type graph goes through — member
 * lookup and subclass checks alike. Callers ask for a member of a class; the
 * implementation walks used traits, then the parent chain, then interfaces,
 * so no member kind can see a different hierarchy than another.
 */
interface MemberResolverInterface
{
    public function findConstant(
        ClasslikeName $class,
        ClasslikeConstantName $constant,
        Visibility $minVisibility,
    ): ?ClasslikeConstantInfo;

    public function findEnumCase(ClasslikeName $class, EnumCaseName $case): ?EnumCaseInfo;

    public function findMethod(
        ClasslikeName $class,
        string $name,
        Visibility $minVisibility,
    ): ?MethodInfo;

    public function findProperty(
        ClasslikeName $class,
        string $name,
        Visibility $minVisibility,
    ): ?PropertyInfo;

    /**
     * @return list<ClasslikeConstantInfo>
     */
    public function getConstants(ClasslikeName $class, Visibility $minVisibility): array;

    /**
     * @return list<EnumCaseInfo>
     */
    public function getEnumCases(ClasslikeName $class): array;

    /**
     * @return list<MemberInfoInterface>
     */
    public function getMembersOfKind(
        ClasslikeName $class,
        MemberKind $kind,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array;

    /**
     * @return list<MethodInfo>
     */
    public function getMethods(
        ClasslikeName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array;

    /**
     * @return list<PropertyInfo>
     */
    public function getProperties(
        ClasslikeName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
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
