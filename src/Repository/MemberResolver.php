<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\LateBinding;
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
        ?ClasslikeName $static = null,
    ): ?ClasslikeConstantInfo {
        return $this->findMember($class, MemberKind::Constant, $name, $from, $static ?? $class);
    }

    public function findEnumCase(ClasslikeName $class, string $name): ?EnumCaseInfo
    {
        return $this->findMember($class, MemberKind::EnumCase, $name, null, $class);
    }

    public function findMethod(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
        ?ClasslikeName $static = null,
    ): ?MethodInfo {
        $origin = $this->source->lookupClassLike($class);
        $found = $origin === null ? null : $this->declaredMethod($origin, $name, $from);

        return $found === null ? null : $this->bind($found[0], $found[1], $static ?? $class);
    }

    public function findProperty(
        ClasslikeName $class,
        string $name,
        ?ClasslikeName $from,
        ?ClasslikeName $static = null,
    ): ?PropertyInfo {
        return $this->findMember($class, MemberKind::Property, $name, $from, $static ?? $class);
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
        ?ClasslikeName $static = null,
    ): array {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return [];
        }
        $found = $this->collectMembers($origin, $kind, $from, $filter);
        if ($kind->isMethod()) {
            $found = $this->applyMethodAliases($found, $origin, $from, $filter);
        }

        return array_map(
            fn (array $member): MemberInfoInterface => $this->bind($member[0], $member[1], $static ?? $class),
            $found,
        );
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
        foreach ($this->descend($origin, $origin, [], $seen) as [$info]) {
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
     * Every member of $kind on $origin that code in $from may use, with the
     * class declaring each.
     *
     * @return list<array{MemberInfoInterface, ClassInfo}>
     */
    private function collectMembers(
        ClassInfo $origin,
        MemberKind $kind,
        ?ClasslikeName $from,
        MemberFilter $filter,
    ): array {
        $collected = [];
        foreach ($this->declarations($origin, $kind, null) as $declarations) {
            $found = $this->usable($declarations, $kind, $from, $filter);
            if ($found !== null) {
                $collected[] = $found;
            }
        }

        return $collected;
    }

    /**
     * The member a name resolves to on $class for code in $from, with
     * `static` naming $static.
     *
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
        ClasslikeName $static,
    ): ?MemberInfoInterface {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return null;
        }
        $found = $this->findDeclared($origin, $kind, $name, $from);

        return $found === null ? null : $this->bind($found[0], $found[1], $static);
    }

    /**
     * The member a name resolves to on $origin for code in $from, as declared,
     * with the class declaring it.
     *
     * @phpstan-return (
     *   $kind is MemberKind::Method ? ?array{MethodInfo, ClassInfo} : ?array{MemberInfoInterface, ClassInfo}
     * )
     */
    private function findDeclared(ClassInfo $origin, MemberKind $kind, string $name, ?ClasslikeName $from): ?array
    {
        $declarations = $this->declarations($origin, $kind, $kind->keyFor($name));

        return $declarations === [] ? null : $this->usable($declarations[0], $kind, $from, MemberFilter::All);
    }

    /**
     * $member with `static` naming $static, and `self` and `parent` naming
     * $declaring and its parent (PHP manual, Late Static Bindings).
     *
     * @template T of MemberInfoInterface
     * @param T $member
     * @return T
     */
    private function bind(MemberInfoInterface $member, ClassInfo $declaring, ClasslikeName $static): MemberInfoInterface
    {
        return $member->resolveLateBound(new LateBinding($static, $declaring->name, $declaring->parent));
    }

    /**
     * Each member name's declarations along the walk from $origin, nearest
     * first, each paired with the class that declares it — the class using a
     * trait for a member the trait brings in. Only $wanted's when given.
     *
     * @return list<non-empty-list<array{MemberInfoInterface, ClassInfo}>>
     */
    private function declarations(ClassInfo $origin, MemberKind $kind, ?string $wanted): array
    {
        $byKey = [];
        $seen = [];
        foreach ($this->descend($origin, $origin, [], $seen) as [$classInfo, $declaringClass, $exclusions]) {
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
     * manual, Visibility; `zend_std_get_method` and `zend_verify_const_access`
     * in php-src). The nearest declaration is what the name means. A private
     * member is usable only from its declaring class; a protected one from
     * classes related to the class it is checked against, which
     * {@see MemberKind::checksAccessByFirstDeclaration()} chooses. For those
     * kinds, code in a class also reaches that class's own private member on
     * an instance of a subclass.
     *
     * @param non-empty-list<array{MemberInfoInterface, ClassInfo}> $declarations
     * @return ?array{MemberInfoInterface, ClassInfo}
     */
    private function usable(
        array $declarations,
        MemberKind $kind,
        ?ClasslikeName $from,
        MemberFilter $filter,
    ): ?array {
        $found = $declarations[0];
        $byFirstDeclaration = $kind->checksAccessByFirstDeclaration();
        if ($byFirstDeclaration && $from !== null && !$from->equals($found[1]->name)) {
            foreach ($declarations as $candidate) {
                // A class's own member precedes its traits' in the walk, as in its method table.
                if ($candidate[0]->getVisibility() === Visibility::Private && $from->equals($candidate[1]->name)) {
                    $found = $candidate;
                    break;
                }
            }
        }

        [$member, $declaring] = $found;
        if (!$this->passes($member, $filter)) {
            return null;
        }
        $checkedAgainst = $byFirstDeclaration ? self::firstDeclarer($declarations) : $declaring->name;
        $usable = match ($member->getVisibility()) {
            Visibility::Public => true,
            Visibility::Private => $from !== null && $from->equals($declaring->name),
            Visibility::Protected => $from !== null && $this->isRelated($from, $checkedAgainst),
        };

        return $usable ? $found : null;
    }

    /**
     * The class that first declared a name along the parent chain: the
     * farthest declaration that is not private, which later ones override.
     *
     * @param non-empty-list<array{MemberInfoInterface, ClassInfo}> $declarations
     */
    private static function firstDeclarer(array $declarations): ClasslikeName
    {
        $first = $declarations[0][1]->name;
        foreach ($declarations as [$member, $declaring]) {
            if ($member->getVisibility() !== Visibility::Private) {
                $first = $declaring->name;
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
     * @return iterable<array{ClassInfo, ClassInfo, list<string>}> Each class-like
     *         with the class its members are declared in, and its excluded methods
     */
    private function descend(
        ClassInfo $classInfo,
        ClassInfo $declaring,
        array $exclusions,
        array &$seen,
    ): iterable {
        $key = NameKind::ClassLike->normalize($classInfo->name->qualifiedName);
        if (array_key_exists($key, $seen)) {
            return;
        }
        $seen[$key] = true;

        yield [$classInfo, $declaring, $exclusions];

        foreach ($this->supertypes($classInfo) as [$superInfo, $isTrait, $superExclusions]) {
            yield from $this->descend($superInfo, $isTrait ? $declaring : $superInfo, $superExclusions, $seen);
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
        $supertypes = [];
        foreach ($this->usedTraits($classInfo) as [$trait, $exclusions]) {
            $supertypes[] = [$trait, true, $exclusions];
        }
        $names = $classInfo->interfaces;
        if ($classInfo->parent !== null) {
            array_unshift($names, $classInfo->parent);
        }
        foreach ($names as $name) {
            $info = $this->source->lookupClassLike($name);
            if ($info !== null) {
                $supertypes[] = [$info, false, []];
            }
        }

        return $supertypes;
    }

    /**
     * The traits a class-like uses, each with the method names an `insteadof`
     * clause excludes from it. PHP rejects `use` naming anything but a trait
     * (`zend_do_link_class` in php-src), so a name that is not one brings in
     * nothing.
     *
     * @return list<array{ClassInfo, list<string>}>
     */
    private function usedTraits(ClassInfo $classInfo): array
    {
        $traits = [];
        foreach ($classInfo->traits as $name) {
            $trait = $this->source->lookupClassLike($name);
            if ($trait?->isTrait() === true) {
                $key = NameKind::ClassLike->normalize($name->qualifiedName);
                $traits[] = [$trait, $classInfo->traitExclusions[$key] ?? []];
            }
        }

        return $traits;
    }

    /**
     * Merge $origin's `as` alias methods into $members, overriding same-name
     * entries the walk produced. The alias is invisible if its resolved source
     * cannot be found or fails the visibility gate.
     *
     * @param list<array{MemberInfoInterface, ClassInfo}> $members
     * @return list<array{MemberInfoInterface, ClassInfo}>
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
        foreach ($members as $index => [$member]) {
            $byKey[MemberKind::Method->keyFor($member->getName()->name)] = $index;
        }
        foreach ($origin->traitAliases as $alias) {
            $aliased = $this->usableAlias($origin, $alias, $from, $filter);
            if ($aliased === null) {
                continue;
            }
            $key = MemberKind::Method->keyFor($aliased->getName()->name);
            if (array_key_exists($key, $byKey)) {
                $members[$byKey[$key]] = [$aliased, $origin];
            } else {
                $byKey[$key] = count($members);
                $members[] = [$aliased, $origin];
            }
        }

        return $members;
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
        if ($aliased === null) {
            return null;
        }
        $declarations = [[$aliased, $origin]];
        return $this->usable($declarations, MemberKind::Method, $from, $filter) === null ? null : $aliased;
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

    /**
     * An alias naming its trait draws only from that trait, which PHP requires
     * $origin to use ("Required Trait %s wasn't added to %s",
     * zend_inheritance.c).
     */
    private function findAliasSource(ClassInfo $origin, TraitAlias $alias): ?MethodInfo
    {
        foreach ($this->usedTraits($origin) as [$trait]) {
            if ($alias->trait === null) {
                $method = $this->traitMethod($trait, $alias->method);
                if ($method !== null) {
                    return $method;
                }
            } elseif ($alias->trait->equals($trait->name)) {
                return $this->traitMethod($trait, $alias->method);
            }
        }

        return null;
    }

    /**
     * A trait's method as declared, so an alias of it binds `self` to the
     * class using the trait.
     */
    private function traitMethod(ClassInfo $trait, string $name): ?MethodInfo
    {
        return $this->declaredMethod($trait, $name, $trait->name)[0] ?? null;
    }

    /**
     * The method a name resolves to on $origin for code in $from, as declared:
     * one of $origin's trait aliases, else the method the walk finds, with the
     * class declaring it.
     *
     * @return ?array{MethodInfo, ClassInfo}
     */
    private function declaredMethod(ClassInfo $origin, string $name, ?ClasslikeName $from): ?array
    {
        $aliased = $this->findAliasedMethod($origin, $name, $from);
        return $aliased === null
            ? $this->findDeclared($origin, MemberKind::Method, $name, $from)
            : [$aliased, $origin];
    }
}
