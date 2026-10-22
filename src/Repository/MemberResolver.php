<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberInfoInterface;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;

/**
 * Resolves class members with inheritance traversal.
 *
 * Class-like metadata is read through the {@see SymbolSourceInterface} seam, so
 * the member walk sees the same coverage — open documents overriding the workspace,
 * vendored code, and built-ins — as every other consumer of symbol knowledge.
 *
 * Every member kind is answered by the one walk below, over the one type graph
 * {@see self::supertypes()} defines. What varies between kinds lives in
 * {@see MemberKind}, so no two kinds can come to disagree about the hierarchy or
 * about how a member name is matched.
 */
final class MemberResolver implements MemberResolverInterface
{
    public function __construct(
        private readonly SymbolSourceInterface $source,
    ) {
    }

    public function findConstant(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
    ): ?ClasslikeConstantInfo {
        return $this->findMember($class, MemberKind::Constant, $name, $from);
    }

    public function findEnumCase(ClasslikeName $class, string $name): ?EnumCaseInfo
    {
        return $this->findMember($class, MemberKind::EnumCase, $name, null);
    }

    public function findMethod(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
    ): ?MethodInfo {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return null;
        }
        $aliased = $this->findAliasedMethod($origin, $name, $from);
        if ($aliased !== null) {
            return $aliased;
        }

        return $this->findMember($class, MemberKind::Method, $name, $from, $origin);
    }

    public function findProperty(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
    ): ?PropertyInfo {
        return $this->findMember($class, MemberKind::Property, $name, $from);
    }

    /**
     * Every member of the given kind on $class that code in $from may use.
     * Kind-parameterized so the caller can iterate over kinds without a
     * per-kind method for every one, which is how member completion collapses
     * to one loop over MemberKind cases.
     *
     * @return list<MemberInfoInterface>
     */
    public function getMembersOfKind(
        ClasslikeName $class,
        MemberKind $kind,
        ?ClasslikeName $from,
        MemberFilter $filter = MemberFilter::All,
    ): array {
        if (!$kind->isMethod()) {
            return $this->collectMembers($class, $kind, $from, $filter);
        }
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return [];
        }
        $members = $this->collectMembers($class, $kind, $from, $filter, $origin);

        return $this->applyMethodAliases($members, $origin, $from, $filter);
    }

    public function isSubclassOf(ClasslikeName $class, ClasslikeName $potentialParent): bool
    {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return false;
        }
        $originKey = NameKind::ClassLike->normalize($class->qualifiedName);
        $targetKey = NameKind::ClassLike->normalize($potentialParent->qualifiedName);
        if ($originKey === $targetKey) {
            return false;
        }

        $seen = [];
        foreach ($this->descend($origin, $origin->name, [], $seen) as [$info]) {
            // is_subclass_of never treats a trait as a parent; the walk yields
            // used traits so member kinds see them, so filter them here.
            if ($info->isTrait()) {
                continue;
            }
            $key = NameKind::ClassLike->normalize($info->name->qualifiedName);
            if ($key === $originKey) {
                continue;
            }
            if ($key === $targetKey) {
                return true;
            }
        }

        return false;
    }

    public function isInterface(ClasslikeName $class): bool
    {
        return $this->source->lookupClassLike($class)?->isInterface() ?? false;
    }

    public function isTrait(ClasslikeName $class): bool
    {
        return $this->source->lookupClassLike($class)?->isTrait() ?? false;
    }

    /**
     * Every member of $kind on $class that code in $from may use.
     *
     * @return list<MemberInfoInterface>
     */
    private function collectMembers(
        ClasslikeName $class,
        MemberKind $kind,
        ?ClasslikeName $from,
        MemberFilter $filter,
        ?ClassInfo $origin = null,
    ): array {
        $origin ??= $this->source->lookupClassLike($class);
        if ($origin === null) {
            return [];
        }

        $collected = [];
        foreach ($this->declarations($origin, $kind, null) as $declarations) {
            $member = $this->usable($declarations, $from, $filter);
            if ($member !== null) {
                $collected[] = $member;
            }
        }

        return $collected;
    }

    /**
     * @phpstan-return (
     *   $kind is MemberKind::Method ? ?MethodInfo : (
     *     $kind is MemberKind::Property ? ?PropertyInfo : (
     *       $kind is MemberKind::Constant ? ?ClasslikeConstantInfo : (
     *         $kind is MemberKind::EnumCase ? ?EnumCaseInfo : ?MemberInfoInterface
     *       )
     *     )
     *   )
     * )
     */
    private function findMember(
        ClasslikeName $class,
        MemberKind $kind,
        string $name,
        ?ClasslikeName $from,
        ?ClassInfo $origin = null,
    ): ?MemberInfoInterface {
        $origin ??= $this->source->lookupClassLike($class);
        if ($origin === null) {
            return null;
        }
        $declarations = $this->declarations($origin, $kind, $kind->keyFor($name));

        return $declarations === [] ? null : $this->usable($declarations[0], $from, MemberFilter::All);
    }

    /**
     * Each member name's declarations along the walk from $origin, nearest
     * first, each paired with the class that declares it — the class using a
     * trait for a member the trait brings in. Only $wanted's when given.
     *
     * @return list<non-empty-list<array{MemberInfoInterface, ClasslikeName}>>
     */
    private function declarations(ClassInfo $origin, MemberKind $kind, ?string $wanted): array
    {
        $byKey = [];
        $seen = [];
        foreach ($this->descend($origin, $origin->name, [], $seen) as [$classInfo, $declaringClass, $exclusions]) {
            foreach ($kind->membersOf($classInfo) as $name => $member) {
                $key = $kind->keyFor($name);
                if ($wanted !== null && $key !== $wanted) {
                    continue;
                }
                if ($kind->isMethod() && in_array($name, $exclusions, true)) {
                    continue;
                }
                $byKey[$key][] = [$member, $declaringClass];
            }
        }

        return array_values($byKey);
    }

    /**
     * The member one name resolves to, if code in $from may use it (PHP
     * manual, Visibility; `zend_std_get_method` in php-src). The nearest
     * declaration is what the name means, except that code in a class reaches
     * that class's own private member even on an instance of a subclass. A
     * private member is usable only from its declaring class; a protected one
     * from classes related to the class that first declared the name.
     *
     * @param non-empty-list<array{MemberInfoInterface, ClasslikeName}> $declarations
     */
    private function usable(array $declarations, ?ClasslikeName $from, MemberFilter $filter): ?MemberInfoInterface
    {
        [$member, $declaringClass] = $declarations[0];
        if ($from !== null && !$from->equals($declaringClass)) {
            foreach ($declarations as [$candidate, $candidateClass]) {
                if ($candidate->getVisibility() === Visibility::Private && $from->equals($candidateClass)) {
                    [$member, $declaringClass] = [$candidate, $candidateClass];
                }
            }
        }

        if (!$this->passes($member, $filter)) {
            return null;
        }
        $usable = match ($member->getVisibility()) {
            Visibility::Public => true,
            Visibility::Private => $from !== null && $from->equals($declaringClass),
            Visibility::Protected => $from !== null && $this->isRelated($from, self::firstDeclarer($declarations)),
        };

        return $usable ? $member : null;
    }

    /**
     * The class that first declared a name along the parent chain: the
     * farthest declaration that is not private, which later ones override.
     *
     * @param non-empty-list<array{MemberInfoInterface, ClasslikeName}> $declarations
     */
    private static function firstDeclarer(array $declarations): ClasslikeName
    {
        $first = $declarations[0][1];
        foreach ($declarations as [$member, $declaringClass]) {
            if ($member->getVisibility() !== Visibility::Private) {
                $first = $declaringClass;
            }
        }
        return $first;
    }

    private function isRelated(ClasslikeName $a, ClasslikeName $b): bool
    {
        return $a->equals($b) || $this->isSubclassOf($a, $b) || $this->isSubclassOf($b, $a);
    }

    /**
     * @param list<string> $exclusions
     * @param array<string, true> $seen
     * @return iterable<array{ClassInfo, ClasslikeName, list<string>}>
     */
    private function descend(
        ClassInfo $classInfo,
        ClasslikeName $declaringClass,
        array $exclusions,
        array &$seen,
    ): iterable {
        $key = NameKind::ClassLike->normalize($classInfo->name->qualifiedName);
        if (array_key_exists($key, $seen)) {
            return;
        }
        $seen[$key] = true;

        yield [$classInfo, $declaringClass, $exclusions];

        foreach ($this->supertypes($classInfo) as [$superInfo, $isTrait, $superExclusions]) {
            $superDeclaringClass = $isTrait ? $declaringClass : $superInfo->name;
            yield from $this->descend($superInfo, $superDeclaringClass, $superExclusions, $seen);
        }
    }

    private function passes(MemberInfoInterface $member, MemberFilter $filter): bool
    {
        // Keyed by MemberFilter case name; the closure per case is picked by
        // string lookup, avoiding a match on the enum that the kind-branch rule
        // would flag.
        $filters = [
            'All' => static fn(MemberInfoInterface $m): bool => true,
            'Static' => static fn(MemberInfoInterface $m): bool => $m->isStatic(),
            'Instance' => static fn(MemberInfoInterface $m): bool => !$m->isStatic(),
        ];
        return $filters[$filter->name]($member);
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
     *         whether it is a used trait, whose members are flattened into the
     *         using class, and any excluded method names.
     */
    private function supertypes(ClassInfo $classInfo): array
    {
        $names = [];
        foreach ($classInfo->traits as $trait) {
            $key = NameKind::ClassLike->normalize($trait->qualifiedName);
            $names[] = [$trait, true, $classInfo->traitExclusions[$key] ?? []];
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
     * Merge $origin's `as` alias methods into $members, overriding same-name
     * entries the walk produced. The alias is invisible if its resolved source
     * cannot be found or fails the visibility gate.
     *
     * @param list<MemberInfoInterface> $members
     * @return list<MemberInfoInterface>
     */
    private function applyMethodAliases(
        array $members,
        ClassInfo $origin,
        ?ClasslikeName $from,
        MemberFilter $filter,
    ): array {
        if ($origin->traitAliases === []) {
            return $members;
        }
        $byKey = [];
        foreach ($members as $index => $member) {
            $byKey[MemberKind::Method->keyFor($member->getName()->name)] = $index;
        }
        foreach ($origin->traitAliases as $alias) {
            $aliased = $this->usableAlias($origin, $alias, $from, $filter);
            if ($aliased === null) {
                continue;
            }
            $key = MemberKind::Method->keyFor($aliased->getName()->name);
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
     * The visible alias-exposed method matching $name on $origin, if any.
     * Filters aliases by their exposed name before resolving the source so a
     * class with unrelated aliases costs no extra trait walks.
     */
    private function findAliasedMethod(
        ClassInfo $origin,
        string $name,
        ?ClasslikeName $from,
    ): ?MethodInfo {
        $wanted = MemberKind::Method->keyFor($name);
        foreach ($origin->traitAliases as $alias) {
            $aliasName = $alias->newName ?? $alias->method;
            if (MemberKind::Method->keyFor($aliasName) !== $wanted) {
                continue;
            }
            $aliased = $this->usableAlias($origin, $alias, $from, MemberFilter::All);
            if ($aliased !== null) {
                return $aliased;
            }
        }

        return null;
    }

    /**
     * The method an alias exposes, if code in $from may use it: an alias is
     * declared on the class whose `use` clause names it.
     */
    private function usableAlias(
        ClassInfo $origin,
        TraitAlias $alias,
        ?ClasslikeName $from,
        MemberFilter $filter,
    ): ?MethodInfo {
        $aliased = $this->resolveAlias($origin, $alias);
        if ($aliased === null || $this->usable([[$aliased, $origin->name]], $from, $filter) === null) {
            return null;
        }
        return $aliased;
    }

    /**
     * The method a trait `as` alias exposes on $origin. Resolves the source
     * through the one hierarchy walk that finds any other method — the trait
     * the alias names when it names one, otherwise the first trait on $origin
     * that declares the method — so aliases inherit conflict resolution rather
     * than duplicating it.
     */
    private function resolveAlias(ClassInfo $origin, TraitAlias $alias): ?MethodInfo
    {
        $source = $this->findAliasSource($origin, $alias);
        if ($source === null) {
            return null;
        }
        $newName = $alias->newName ?? $alias->method;

        return new MethodInfo(
            name: new MethodName($origin->name, $newName),
            visibility: $alias->newVisibility ?? $source->visibility,
            isStatic: $source->isStatic,
            isAbstract: $source->isAbstract,
            isFinal: $source->isFinal,
            parameters: $source->parameters,
            returnType: $source->returnType,
            docblock: $source->docblock,
            file: $source->file,
            line: $source->line,
            aliasedFrom: $source->name,
        );
    }

    private function findAliasSource(ClassInfo $origin, TraitAlias $alias): ?MethodInfo
    {
        if ($alias->trait !== null) {
            return $this->findMethod($alias->trait, $alias->method, $alias->trait);
        }
        foreach ($origin->traits as $trait) {
            $method = $this->findMethod($trait, $alias->method, $trait);
            if ($method !== null) {
                return $method;
            }
        }

        return null;
    }
}
