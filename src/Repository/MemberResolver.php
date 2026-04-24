<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
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

    /**
     * @return list<MethodInfo>
     */
    public function getMethods(
        ClassName $class,
        Visibility $minVisibility,
        ?bool $static = null,
    ): array {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $methods = [];
        $seen = [];
        $this->collectMethods($classInfo, $minVisibility, $static, $methods, $seen, true);

        return array_values($methods);
    }

    /**
     * @return list<PropertyInfo>
     */
    public function getProperties(
        ClassName $class,
        Visibility $minVisibility,
        ?bool $static = null,
    ): array {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $properties = [];
        $seen = [];
        $this->collectProperties($classInfo, $minVisibility, $static, $properties, $seen, true);

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
     * Get abstract methods from interfaces and parent classes that the class
     * must implement but hasn't yet.
     *
     * @return list<MethodInfo>
     */
    public function getUnimplementedAbstractMethods(ClassName $class): array
    {
        $classInfo = $this->classes->get($class);
        if ($classInfo === null) {
            return [];
        }

        $implementedMethods = [];
        foreach ($classInfo->methods as $method) {
            $implementedMethods[strtolower($method->name->name)] = true;
        }

        $abstractMethods = [];
        $seen = [];

        $this->collectAbstractMethods($classInfo, $abstractMethods, $implementedMethods, $seen);

        return array_values($abstractMethods);
    }

    /**
     * @param array<string, MethodInfo> $abstractMethods
     * @param array<string, true> $implementedMethods
     * @param array<string, true> $seen
     */
    private function collectAbstractMethods(
        ClassInfo $classInfo,
        array &$abstractMethods,
        array $implementedMethods,
        array &$seen,
    ): void {
        $fqn = $classInfo->name->fqn;
        if (array_key_exists($fqn, $seen)) {
            return;
        }
        $seen[$fqn] = true;

        $isInterface = $classInfo->kind === \Firehed\PhpLsp\Domain\ClassKind::Interface_;

        foreach ($classInfo->methods as $method) {
            $key = strtolower($method->name->name);
            if (array_key_exists($key, $implementedMethods)) {
                continue;
            }
            if (array_key_exists($key, $abstractMethods)) {
                continue;
            }
            if ($isInterface || $method->isAbstract) {
                $abstractMethods[$key] = $method;
            }
        }

        foreach ($classInfo->interfaces as $interfaceName) {
            $interfaceInfo = $this->classes->get($interfaceName);
            if ($interfaceInfo !== null) {
                $this->collectAbstractMethods($interfaceInfo, $abstractMethods, $implementedMethods, $seen);
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                $this->collectAbstractMethods($parentInfo, $abstractMethods, $implementedMethods, $seen);
            }
        }
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

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $result = $this->findMethodInHierarchy($traitInfo, $method, $minVisibility, $seen, true);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                return $this->findMethodInHierarchy($parentInfo, $method, $minVisibility, $seen, false);
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

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $result = $this->findPropertyInHierarchy($traitInfo, $property, $minVisibility, $seen, true);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                return $this->findPropertyInHierarchy($parentInfo, $property, $minVisibility, $seen, false);
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

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $result = $this->findConstantInHierarchy($traitInfo, $constant, $minVisibility, $seen, true);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                $result = $this->findConstantInHierarchy($parentInfo, $constant, $minVisibility, $seen, false);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        foreach ($classInfo->interfaces as $interfaceName) {
            $interfaceInfo = $this->classes->get($interfaceName);
            if ($interfaceInfo !== null) {
                $result = $this->findConstantInHierarchy($interfaceInfo, $constant, $minVisibility, $seen, false);
                if ($result !== null) {
                    return $result;
                }
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
        ?bool $static,
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
            if ($static !== null && $methodInfo->isStatic !== $static) {
                continue;
            }
            if (!$this->isAccessible($methodInfo->visibility, $minVisibility, $isOriginClass)) {
                continue;
            }
            $methods[$key] = $methodInfo;
        }

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $this->collectMethods($traitInfo, $minVisibility, $static, $methods, $seen, true);
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                $this->collectMethods($parentInfo, $minVisibility, $static, $methods, $seen, false);
            }
        }
    }

    /**
     * @param array<string, PropertyInfo> $properties
     * @param array<string, true> $seen
     */
    private function collectProperties(
        ClassInfo $classInfo,
        Visibility $minVisibility,
        ?bool $static,
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
            if ($static !== null && $propInfo->isStatic !== $static) {
                continue;
            }
            if (!$this->isAccessible($propInfo->visibility, $minVisibility, $isOriginClass)) {
                continue;
            }
            $properties[$key] = $propInfo;
        }

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $this->collectProperties($traitInfo, $minVisibility, $static, $properties, $seen, true);
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                $this->collectProperties($parentInfo, $minVisibility, $static, $properties, $seen, false);
            }
        }
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

        foreach ($classInfo->traits as $traitName) {
            $traitInfo = $this->classes->get($traitName);
            if ($traitInfo !== null) {
                $this->collectConstants($traitInfo, $minVisibility, $constants, $seen, true);
            }
        }

        if ($classInfo->parent !== null) {
            $parentInfo = $this->classes->get($classInfo->parent);
            if ($parentInfo !== null) {
                $this->collectConstants($parentInfo, $minVisibility, $constants, $seen, false);
            }
        }

        foreach ($classInfo->interfaces as $interfaceName) {
            $interfaceInfo = $this->classes->get($interfaceName);
            if ($interfaceInfo !== null) {
                $this->collectConstants($interfaceInfo, $minVisibility, $constants, $seen, false);
            }
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
