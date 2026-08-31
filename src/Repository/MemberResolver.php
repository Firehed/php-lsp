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
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberInfo;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSource;

/**
 * Resolves class members with inheritance traversal.
 *
 * Class-like metadata is read through the {@see SymbolSource} seam (RFC 1 §4.2), so
 * the member walk sees the same coverage — open documents overriding the workspace,
 * vendored code, and built-ins — as every other consumer of symbol knowledge.
 *
 * Every member kind is answered by the one walk below, over the one type graph
 * {@see self::supertypes()} defines. What varies between kinds lives in
 * {@see MemberKind}, so no two kinds can come to disagree about the hierarchy or
 * about how a member name is matched.
 */
final class MemberResolver
{
    public function __construct(
        private readonly SymbolSource $source,
    ) {
    }

    public function findConstant(
        ClassName $class,
        ConstantName $constant,
        Visibility $minVisibility,
    ): ?ConstantInfo {
        return $this->findMember($class, MemberKind::Constant, ConstantInfo::class, $constant->name, $minVisibility);
    }

    public function findEnumCase(ClassName $class, EnumCaseName $case): ?EnumCaseInfo
    {
        return $this->findMember($class, MemberKind::EnumCase, EnumCaseInfo::class, $case->name, Visibility::Public);
    }

    public function findMethod(
        ClassName $class,
        MethodName $method,
        Visibility $minVisibility,
    ): ?MethodInfo {
        $origin = $this->source->lookupClassLike($class);
        if ($origin !== null) {
            $wanted = MemberKind::Method->keyFor($method->name);
            foreach ($this->aliasedMethods($origin) as $aliasName => $aliased) {
                if (MemberKind::Method->keyFor($aliasName) !== $wanted) {
                    continue;
                }
                if ($this->isVisible($aliased, $minVisibility, MemberFilter::All, true)) {
                    return $aliased;
                }
            }
        }

        return $this->findMember($class, MemberKind::Method, MethodInfo::class, $method->name, $minVisibility);
    }

    public function findProperty(
        ClassName $class,
        PropertyName $property,
        Visibility $minVisibility,
    ): ?PropertyInfo {
        return $this->findMember($class, MemberKind::Property, PropertyInfo::class, $property->name, $minVisibility);
    }

    /**
     * @return list<ConstantInfo>
     */
    public function getConstants(ClassName $class, Visibility $minVisibility): array
    {
        return $this->collectMembers(
            $class,
            MemberKind::Constant,
            ConstantInfo::class,
            $minVisibility,
            MemberFilter::All,
        );
    }

    /**
     * @return list<EnumCaseInfo>
     */
    public function getEnumCases(ClassName $class): array
    {
        return $this->collectMembers(
            $class,
            MemberKind::EnumCase,
            EnumCaseInfo::class,
            Visibility::Public,
            MemberFilter::All,
        );
    }

    /**
     * @return list<MethodInfo>
     */
    public function getMethods(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        $methods = $this->collectMembers($class, MemberKind::Method, MethodInfo::class, $minVisibility, $filter);
        $origin = $this->source->lookupClassLike($class);

        return $origin === null ? $methods : $this->mergeAliasedMethods($methods, $origin, $minVisibility, $filter);
    }

    /**
     * @return list<PropertyInfo>
     */
    public function getProperties(
        ClassName $class,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        return $this->collectMembers($class, MemberKind::Property, PropertyInfo::class, $minVisibility, $filter);
    }

    /**
     * Every member of the given kind visible from $class. Kind-parameterized so the
     * caller can iterate over kinds without a per-kind method for every one, which
     * is how member completion collapses to one loop over MemberKind cases.
     *
     * @return list<MemberInfo>
     */
    public function getMembersOfKind(
        ClassName $class,
        MemberKind $kind,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        $members = $this->collectMembers($class, $kind, MemberInfo::class, $minVisibility, $filter);
        if (!$kind->isMethod()) {
            return $members;
        }
        $origin = $this->source->lookupClassLike($class);

        return $origin === null ? $members : $this->mergeAliasedMethods($members, $origin, $minVisibility, $filter);
    }

    public function isTraitClass(ClassName $class): bool
    {
        return $this->source->lookupClassLike($class)?->kind === ClassKind::Trait_;
    }

    /**
     * Every member of $kind visible from $class, nearest declaration winning.
     * A class's `as` aliases contribute their aliased method after the walk,
     * overriding any same-name conflict resolution the walk arrived at.
     *
     * @template T of MemberInfo
     * @param class-string<T> $memberType The type $kind is stored as.
     * @return list<T>
     */
    private function collectMembers(
        ClassName $class,
        MemberKind $kind,
        string $memberType,
        Visibility $minVisibility,
        MemberFilter $filter,
    ): array {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return [];
        }

        $collected = [];
        foreach ($this->hierarchyOf($origin) as [$classInfo, $isOriginClass, $exclusions]) {
            foreach ($kind->membersOf($classInfo) as $name => $member) {
                $key = $kind->keyFor($name);
                if (array_key_exists($key, $collected) || !$member instanceof $memberType) {
                    continue;
                }
                if ($kind->isMethod() && in_array($name, $exclusions, true)) {
                    continue;
                }
                if ($this->isVisible($member, $minVisibility, $filter, $isOriginClass)) {
                    $collected[$key] = $member;
                }
            }
        }

        return array_values($collected);
    }

    /**
     * @template T of MemberInfo
     * @param class-string<T> $memberType The type $kind is stored as.
     * @return ?T
     */
    private function findMember(
        ClassName $class,
        MemberKind $kind,
        string $memberType,
        string $name,
        Visibility $minVisibility,
    ): ?MemberInfo {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return null;
        }

        $wanted = $kind->keyFor($name);

        foreach ($this->hierarchyOf($origin) as [$classInfo, $isOriginClass, $exclusions]) {
            foreach ($kind->membersOf($classInfo) as $declared => $member) {
                if ($kind->keyFor($declared) !== $wanted || !$member instanceof $memberType) {
                    continue;
                }
                if ($kind->isMethod() && in_array($declared, $exclusions, true)) {
                    continue;
                }
                if ($this->isVisible($member, $minVisibility, MemberFilter::All, $isOriginClass)) {
                    return $member;
                }
            }
        }

        return null;
    }

    /**
     * $origin and every type it inherits members from, in PHP's resolution order,
     * each paired with its isOriginClass flag and the method names excluded from
     * that classInfo's own members (from an `insteadof` clause on the edge). A
     * type reached by more than one edge is visited once; an unresolvable one is
     * skipped.
     *
     * @return iterable<array{ClassInfo, bool, list<string>}>
     */
    private function hierarchyOf(ClassInfo $origin): iterable
    {
        $seen = [];
        yield from $this->descend($origin, true, [], $seen);
    }

    /**
     * @param list<string> $exclusions
     * @param array<string, true> $seen
     * @return iterable<array{ClassInfo, bool, list<string>}>
     */
    private function descend(ClassInfo $classInfo, bool $isOriginClass, array $exclusions, array &$seen): iterable
    {
        $key = NameKind::ClassLike->normalize(QualifiedName::fromClassName($classInfo->name));
        if (array_key_exists($key, $seen)) {
            return;
        }
        $seen[$key] = true;

        yield [$classInfo, $isOriginClass, $exclusions];

        foreach ($this->supertypes($classInfo) as [$superInfo, $superIsOrigin, $superExclusions]) {
            yield from $this->descend($superInfo, $superIsOrigin, $superExclusions, $seen);
        }
    }

    private function isVisible(
        MemberInfo $member,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $isOriginClass,
    ): bool {
        $matchesFilter = match ($filter) {
            MemberFilter::All => true,
            MemberFilter::Static => $member->isStatic(),
            MemberFilter::Instance => !$member->isStatic(),
        };
        if (!$matchesFilter) {
            return false;
        }

        $visibility = $member->getVisibility();
        if (!$visibility->isAccessibleFrom($minVisibility)) {
            return false;
        }

        // A private member is only reachable from the class that declares it.
        return $visibility !== Visibility::Private || $isOriginClass;
    }

    /**
     * The types to search after a class's own members, in PHP's resolution order:
     * used traits, then the parent chain, then interfaces. A trait edge may carry
     * the method names excluded from that trait by an `insteadof` clause on this
     * class. Unresolvable types are skipped.
     *
     * Every member lookup walks the type graph through this one method, so all
     * member kinds see the same hierarchy.
     *
     * @return list<array{ClassInfo, bool, list<string>}> Supertype paired with
     *         its isOriginClass flag and any excluded method names. A trait's
     *         members are flattened into the using class, so its private members
     *         stay visible; a parent's or interface's do not.
     */
    private function supertypes(ClassInfo $classInfo): array
    {
        $names = [];
        foreach ($classInfo->traits as $trait) {
            $names[] = [$trait, true, $classInfo->traitExclusions[$trait->fqn] ?? []];
        }
        if ($classInfo->parent !== null) {
            $names[] = [$classInfo->parent, false, []];
        }
        foreach ($classInfo->interfaces as $interface) {
            $names[] = [$interface, false, []];
        }

        $supertypes = [];
        foreach ($names as [$name, $isOriginClass, $exclusions]) {
            $info = $this->source->lookupClassLike($name);
            if ($info !== null) {
                $supertypes[] = [$info, $isOriginClass, $exclusions];
            }
        }

        return $supertypes;
    }

    /**
     * @template T of MemberInfo
     * @param list<T> $members
     * @return list<T|MethodInfo>
     */
    private function mergeAliasedMethods(
        array $members,
        ClassInfo $origin,
        Visibility $minVisibility,
        MemberFilter $filter,
    ): array {
        $byKey = [];
        foreach ($members as $index => $member) {
            $byKey[MemberKind::Method->keyFor($member->getName()->name)] = $index;
        }

        foreach ($this->aliasedMethods($origin) as $aliasName => $aliased) {
            if (!$this->isVisible($aliased, $minVisibility, $filter, true)) {
                continue;
            }
            $key = MemberKind::Method->keyFor($aliasName);
            if (array_key_exists($key, $byKey)) {
                $members[$byKey[$key]] = $aliased;
            } else {
                $byKey[$key] = count($members);
                $members[] = $aliased;
            }
        }

        return array_values($members);
    }

    /**
     * The methods that $origin's `as` clauses expose. Each alias resolves through
     * the same one hierarchy walk that finds any other method — the trait the
     * alias names when it names one, otherwise the whole graph — so aliases
     * inherit conflict resolution rather than duplicating it.
     *
     * @return iterable<string, MethodInfo>
     */
    private function aliasedMethods(ClassInfo $origin): iterable
    {
        foreach ($origin->traitAliases as $alias) {
            $source = $this->resolveAliasSource($origin, $alias);
            if ($source === null) {
                continue;
            }
            $newName = $alias->newName ?? $alias->method;
            yield $newName => new MethodInfo(
                name: new MethodName($newName),
                visibility: $alias->newVisibility ?? $source->visibility,
                isStatic: $source->isStatic,
                isAbstract: $source->isAbstract,
                isFinal: $source->isFinal,
                parameters: $source->parameters,
                returnType: $source->returnType,
                docblock: $source->docblock,
                file: $source->file,
                line: $source->line,
                declaringClass: $source->declaringClass,
            );
        }
    }

    private function resolveAliasSource(ClassInfo $origin, TraitAlias $alias): ?MethodInfo
    {
        if ($alias->trait !== null) {
            return $this->findMethod($alias->trait, new MethodName($alias->method), Visibility::Private);
        }
        foreach ($origin->traits as $trait) {
            $method = $this->findMethod($trait, new MethodName($alias->method), Visibility::Private);
            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }
}
