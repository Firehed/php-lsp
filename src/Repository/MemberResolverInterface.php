<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
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
        ClassName $class,
        ConstantName $constant,
        Visibility $minVisibility,
    ): ?ConstantInfo;

    public function findEnumCase(ClassName $class, EnumCaseName $case): ?EnumCaseInfo;

    /**
     * Whether $class is a subtype of $potentialParent somewhere along the type
     * graph. Not reflexive, and — matching PHP's `is_subclass_of` — a class is
     * never a subclass of a trait it uses.
     */
    public function isSubclassOf(ClassName $class, ClassName $potentialParent): bool;

    public function findMethod(
        ClassName $class,
        MethodName $method,
        Visibility $minVisibility,
    ): ?MethodInfo;

    public function findProperty(
        ClassName $class,
        PropertyName $property,
        Visibility $minVisibility,
    ): ?PropertyInfo;

    /**
     * @return list<ConstantInfo>
     */
    public function getConstants(ClassName $class, Visibility $minVisibility): array;

    /**
     * @return list<EnumCaseInfo>
     */
    public function getEnumCases(ClassName $class): array;

    /**
     * @return list<MemberInfoInterface>
     */
    public function getMembersOfKind(
        ClassName $class,
        MemberKind $kind,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array;

    /**
     * @return list<MethodInfo>
     */
    public function getMethods(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array;

    /**
     * @return list<PropertyInfo>
     */
    public function getProperties(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array;

    public function isTraitClass(ClassName $class): bool;
}
