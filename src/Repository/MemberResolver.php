<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Resolution\MemberFilter;

/**
 * Resolves class members with inheritance traversal.
 */
final class MemberResolver
{
    public function __construct(
        private readonly ClassRepository $classes,
    ) {
    }

    public function findMethod(
        ClassName $class,
        MethodName $method,
        Visibility $minVisibility,
    ): ?MethodInfo {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return null;
        }

        $seen = [];
        return $this->findMethodInHierarchy($classInfo, $method, $minVisibility, $seen, true);
    }

    public function findProperty(
        ClassName $class,
        PropertyName $property,
        Visibility $minVisibility,
    ): ?PropertyInfo {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return null;
        }

        $seen = [];
        return $this->findPropertyInHierarchy($classInfo, $property, $minVisibility, $seen, true);
    }

    public function findConstant(
        ClassName $class,
        ConstantName $constant,
        Visibility $minVisibility,
    ): ?ConstantInfo {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return null;
        }

        $seen = [];
        return $this->findConstantInHierarchy($classInfo, $constant, $minVisibility, $seen, true);
    }

    public function findEnumCase(ClassName $class, EnumCaseName $case): ?EnumCaseInfo
    {
        $classInfo = $this->classes->get($class);
        return $classInfo?->enumCases[$case->name] ?? null;
    }

    public function isTraitClass(ClassName $class): bool
    {
        return $this->classes->get($class)?->kind === ClassKind::Trait_;
    }

    /**
     * @return list<MethodInfo>
     */
    public function getMethods(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $methods = [];
        $seen = [];
        $this->collectMethods($classInfo, $minVisibility, $filter, $methods, $seen, true);

        return array_values($methods);
    }

    /**
     * @return list<PropertyInfo>
     */
    public function getProperties(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $properties = [];
        $seen = [];
        $this->collectProperties($classInfo, $minVisibility, $filter, $properties, $seen, true);

        return array_values($properties);
    }

    /**
     * @return list<ConstantInfo>
     */
    public function getConstants(ClassName $class, Visibility $minVisibility): array
    {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $constants = [];
        $seen = [];
        $this->collectConstants($classInfo, $minVisibility, $constants, $seen, true);

        return array_values($constants);
    }

    /**
     * @return list<EnumCaseInfo>
     */
    public function getEnumCases(ClassName $class): array
    {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        return array_values($classInfo->enumCases);
    }

    /**
     * @param array<string, true> $seen
     */
    private function findMethodInHierarchy(
        ClassInfo $classInfo,
        MethodName $method,
        Visibility $minVisibility,
        array &$seen,
        bool $isOriginClass,
    ): ?MethodInfo {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return null;
        }
        $seen[$fqn] = true;

        foreach ($classInfo->methods as $methodInfo) {
            if (!$methodInfo->name->equals($method)) {
                continue;
            }
            if ($this->isAccessible($methodInfo->visibility, $minVisibility, $isOriginClass)) {
                return $methodInfo;
            }
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $result = $this->findMethodInHierarchy($superInfo, $method, $minVisibility, $seen, $superIsOrigin);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function findPropertyInHierarchy(
        ClassInfo $classInfo,
        PropertyName $property,
        Visibility $minVisibility,
        array &$seen,
        bool $isOriginClass,
    ): ?PropertyInfo {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return null;
        }
        $seen[$fqn] = true;

        if (array_key_exists($property->name, $classInfo->properties)) {
            $propInfo = $classInfo->properties[$property->name];
            if ($this->isAccessible($propInfo->visibility, $minVisibility, $isOriginClass)) {
                return $propInfo;
            }
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $result = $this->findPropertyInHierarchy($superInfo, $property, $minVisibility, $seen, $superIsOrigin);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $seen
     */
    private function findConstantInHierarchy(
        ClassInfo $classInfo,
        ConstantName $constant,
        Visibility $minVisibility,
        array &$seen,
        bool $isOriginClass,
    ): ?ConstantInfo {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return null;
        }
        $seen[$fqn] = true;

        if (array_key_exists($constant->name, $classInfo->constants)) {
            $constInfo = $classInfo->constants[$constant->name];
            if ($this->isAccessible($constInfo->visibility, $minVisibility, $isOriginClass)) {
                return $constInfo;
            }
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $result = $this->findConstantInHierarchy($superInfo, $constant, $minVisibility, $seen, $superIsOrigin);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param array<string, MethodInfo> $methods
     * @param array<string, true> $seen
     */
    private function collectMethods(
        ClassInfo $classInfo,
        Visibility $minVisibility,
        MemberFilter $filter,
        array &$methods,
        array &$seen,
        bool $isOriginClass,
    ): void {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return;
        }
        $seen[$fqn] = true;

        foreach ($classInfo->methods as $methodInfo) {
            $key = strtolower($methodInfo->name->name);
            if (array_key_exists($key, $methods)) {
                continue;
            }
            if (!$this->matchesFilter($methodInfo->isStatic, $filter)) {
                continue;
            }
            if (!$this->isAccessible($methodInfo->visibility, $minVisibility, $isOriginClass)) {
                continue;
            }
            $methods[$key] = $methodInfo;
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $this->collectMethods($superInfo, $minVisibility, $filter, $methods, $seen, $superIsOrigin);
        }
    }

    /**
     * @param array<string, PropertyInfo> $properties
     * @param array<string, true> $seen
     */
    private function collectProperties(
        ClassInfo $classInfo,
        Visibility $minVisibility,
        MemberFilter $filter,
        array &$properties,
        array &$seen,
        bool $isOriginClass,
    ): void {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return;
        }
        $seen[$fqn] = true;

        foreach ($classInfo->properties as $key => $propInfo) {
            if (array_key_exists($key, $properties)) {
                continue;
            }
            if (!$this->matchesFilter($propInfo->isStatic, $filter)) {
                continue;
            }
            if (!$this->isAccessible($propInfo->visibility, $minVisibility, $isOriginClass)) {
                continue;
            }
            $properties[$key] = $propInfo;
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $this->collectProperties($superInfo, $minVisibility, $filter, $properties, $seen, $superIsOrigin);
        }
    }

    /**
     * The types to search after a class's own members, in PHP's resolution order:
     * used traits, then the parent chain, then interfaces. Unresolvable types are
     * skipped.
     *
     * Every member lookup walks the type graph through this one method, so all
     * member kinds see the same hierarchy.
     *
     * @return list<array{ClassInfo, bool}> Supertype paired with its isOriginClass
     *         flag. A trait's members are flattened into the using class, so its
     *         private members stay visible; a parent's or interface's do not.
     */
    private function supertypes(ClassInfo $classInfo): array
    {
        $names = [];
        foreach ($classInfo->traits as $trait) {
            $names[] = [$trait, true];
        }
        if ($classInfo->parent !== null) {
            $names[] = [$classInfo->parent, false];
        }
        foreach ($classInfo->interfaces as $interface) {
            $names[] = [$interface, false];
        }

        $supertypes = [];
        foreach ($names as [$name, $isOriginClass]) {
            $info = $this->classes->get($name);
            if ($info !== null) {
                $supertypes[] = [$info, $isOriginClass];
            }
        }

        return $supertypes;
    }

    private function matchesFilter(bool $isStatic, MemberFilter $filter): bool
    {
        return match ($filter) {
            MemberFilter::All => true,
            MemberFilter::Static => $isStatic,
            MemberFilter::Instance => !$isStatic,
        };
    }

    /**
     * @param array<string, ConstantInfo> $constants
     * @param array<string, true> $seen
     */
    private function collectConstants(
        ClassInfo $classInfo,
        Visibility $minVisibility,
        array &$constants,
        array &$seen,
        bool $isOriginClass,
    ): void {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return;
        }
        $seen[$fqn] = true;

        foreach ($classInfo->constants as $key => $constInfo) {
            if (array_key_exists($key, $constants)) {
                continue;
            }
            if (!$this->isAccessible($constInfo->visibility, $minVisibility, $isOriginClass)) {
                continue;
            }
            $constants[$key] = $constInfo;
        }

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin]) {
            $this->collectConstants($superInfo, $minVisibility, $constants, $seen, $superIsOrigin);
        }
    }

    private function isAccessible(
        Visibility $memberVisibility,
        Visibility $minVisibility,
        bool $isOriginClass,
    ): bool {
        if (!$memberVisibility->isAccessibleFrom($minVisibility)) {
            return false;
        }

        if ($memberVisibility === Visibility::Private) {
            return $isOriginClass;
        }

        return true;
    }
}
