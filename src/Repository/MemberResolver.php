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
 * the member tables see the same coverage — open documents overriding the
 * workspace, vendored code, and built-ins — as every other consumer of symbol
 * knowledge.
 *
 * Every member question is answered from a class-like's member table, built
 * in {@see self::table()} the way PHP links the class. What varies between
 * kinds lives in {@see MemberKind}, so no two kinds can come to disagree about
 * the hierarchy or about how a member name is matched.
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
        return $this->findMember($class, MemberKind::Method, $name, $from, $static ?? $class);
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
        $built = [];
        $members = [];
        foreach (array_keys($this->table($origin, $kind, $built)) as $key) {
            $entry = $this->reach($origin, $kind, $key, $from, $filter, $built);
            if ($entry !== null) {
                $members[] = $this->bind($entry, $static ?? $class);
            }
        }

        return $members;
    }

    public function isSubclassOf(ClasslikeName $class, ClasslikeName $potentialParent): bool
    {
        $origin = $this->source->lookupClassLike($class);
        if ($origin === null) {
            return false;
        }
        $targetKey = NameKind::ClassLike->normalize($potentialParent->qualifiedName);
        $seen = [NameKind::ClassLike->normalize($class->qualifiedName) => true];
        $pending = $this->inheritedTypes($origin);
        while ($pending !== []) {
            $info = array_shift($pending);
            $key = NameKind::ClassLike->normalize($info->name->qualifiedName);
            if (array_key_exists($key, $seen)) {
                continue;
            }
            if ($key === $targetKey) {
                return true;
            }
            $seen[$key] = true;
            array_push($pending, ...$this->inheritedTypes($info));
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
        $built = [];
        $entry = $this->reach($origin, $kind, $kind->keyFor($name), $from, MemberFilter::All, $built);

        return $entry === null ? null : $this->bind($entry, $static);
    }

    /**
     * The entry's member with `static` naming $static, and `self` and `parent`
     * naming the class holding it and that class's parent (PHP manual, Late
     * Static Bindings).
     */
    private function bind(MemberEntry $entry, ClasslikeName $static): MemberInfoInterface
    {
        return $entry->member->resolveLateBound(
            new LateBinding($static, $entry->declaring->name, $entry->declaring->parent),
        );
    }

    /**
     * The entry a name reaches on $class, if code in $from may use it (PHP
     * manual, Visibility). A private member is usable only from the class
     * holding it; a protected one from classes related to its root.
     *
     * @param array<string, array<string, MemberEntry>> $built
     */
    private function reach(
        ClassInfo $class,
        MemberKind $kind,
        string $key,
        ?ClasslikeName $from,
        MemberFilter $filter,
        array &$built,
    ): ?MemberEntry {
        $entry = $this->table($class, $kind, $built)[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($from !== null && $kind->checksAccessByFirstDeclaration() && !$from->equals($entry->declaring->name)) {
            $entry = $this->ownPrivate($class, $kind, $key, $from, $entry, $built) ?? $entry;
        }
        if (!$this->passes($entry->member, $filter)) {
            return null;
        }
        $usable = match ($entry->member->getVisibility()) {
            Visibility::Public => true,
            Visibility::Private => $from !== null && $from->equals($entry->declaring->name),
            Visibility::Protected => $from !== null && $this->isRelated($from, $entry->root()),
        };

        return $usable ? $entry : null;
    }

    /**
     * The private member $from declares under the name, when code in $from
     * reaches the name on an instance of a subclass and PHP uses that member
     * instead of $found ({@see MemberKind::prefersOwnPrivate()}).
     *
     * @param array<string, array<string, MemberEntry>> $built
     */
    private function ownPrivate(
        ClassInfo $class,
        MemberKind $kind,
        string $key,
        ClasslikeName $from,
        MemberEntry $found,
        array &$built,
    ): ?MemberEntry {
        $scope = $this->source->lookupClassLike($from);
        $own = $scope === null ? null : ($this->table($scope, $kind, $built)[$key] ?? null);
        if (
            $own === null
            || $own->member->getVisibility() !== Visibility::Private
            || !$own->declaring->name->equals($from)
            || !$kind->prefersOwnPrivate($own->member, $found->member)
        ) {
            return null;
        }

        return $this->isSubclassOf($class->name, $from) ? $own : null;
    }

    private function isRelated(ClasslikeName $a, ClasslikeName $b): bool
    {
        return $a->equals($b) || $this->isSubclassOf($a, $b) || $this->isSubclassOf($b, $a);
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
     * $class's table of $kind members, keyed by {@see MemberKind::keyFor()},
     * built in the order PHP (as of 8.5) adds members when it loads a class:
     * its own members, then what its traits bring in before inheritance, then
     * what it inherits from its parent, then its traits' abstract methods,
     * then what its interfaces declare that it lacks.
     *
     * @param array<string, array<string, MemberEntry>> $built The tables already
     *        built for this lookup, so each class-like's is built once
     * @param list<string> $building The class-likes whose tables are being
     *        built; reaching one again is a cycle PHP rejects, and it brings in
     *        nothing
     * @return array<string, MemberEntry>
     */
    private function table(ClassInfo $class, MemberKind $kind, array &$built, array $building = []): array
    {
        $key = NameKind::ClassLike->normalize($class->name->qualifiedName);
        if (array_key_exists($key, $built)) {
            return $built[$key];
        }
        if (in_array($key, $building, true)) {
            return [];
        }
        $building[] = $key;

        $table = [];
        foreach ($kind->membersOf($class) as $name => $member) {
            $table[$kind->keyFor($name)] = new MemberEntry($member, $class);
        }
        $traits = [];
        foreach ($this->usedTraits($class) as [$trait, $exclusions]) {
            $traits[] = [$trait, $exclusions, $this->table($trait, $kind, $built, $building)];
        }
        $aliases = $kind->takesTraitAdaptations() ? $this->aliasesByTrait($class, $traits) : [];
        $this->bindTraits($table, $class, $kind, $traits, $aliases, beforeInheritance: true);
        $parent = $this->parentOf($class);
        if ($parent !== null) {
            $this->inherit($table, $this->table($parent, $kind, $built, $building), $kind);
        }
        $this->bindTraits($table, $class, $kind, $traits, $aliases, beforeInheritance: false);
        foreach ($this->interfacesOf($class) as $interface) {
            $table += $this->table($interface, $kind, $built, $building);
        }

        return $built[$key] = $table;
    }

    /**
     * Binds the members $class's traits bring in, at one of PHP's two passes
     * around inheritance. Each trait brings in what its own table holds. For
     * a method, every alias naming it is added first under the alias's name;
     * then the method itself, unless an `insteadof` excludes it from that
     * trait, which PHP applies only before inheritance.
     *
     * @param array<string, MemberEntry> $table
     * @param list<array{ClassInfo, list<string>, array<string, MemberEntry>}> $traits
     * @param array<int, list<TraitAlias>> $aliases
     */
    private function bindTraits(
        array &$table,
        ClassInfo $class,
        MemberKind $kind,
        array $traits,
        array $aliases,
        bool $beforeInheritance,
    ): void {
        foreach ($traits as $index => [, $exclusions, $traitTable]) {
            $excluded = [];
            if ($kind->takesTraitAdaptations() && $beforeInheritance) {
                foreach ($exclusions as $name) {
                    $excluded[$kind->keyFor($name)] = true;
                }
            }
            foreach ($traitTable as $key => $entry) {
                $member = $entry->member;
                if ($kind->bindsBeforeInheritance($member) !== $beforeInheritance) {
                    continue;
                }
                $renaming = [];
                foreach ($aliases[$index] ?? [] as $alias) {
                    if ($kind->keyFor($alias->method) !== $key) {
                        continue;
                    }
                    if ($alias->newName === null) {
                        $renaming[] = $alias;
                    } else {
                        $this->addTraitMember($table, $class, $kind, $this->aliased($member, $alias, $class));
                    }
                }
                if (array_key_exists($key, $excluded)) {
                    continue;
                }
                foreach ($renaming as $alias) {
                    $member = $this->aliased($member, $alias, $class);
                }
                $this->addTraitMember($table, $class, $kind, $member);
            }
        }
    }

    /**
     * $class's aliases grouped by the used trait each draws from, by its index
     * in $traits: the trait it names, else the first whose table has the
     * method (PHP rejects one found in several). One naming a trait $class
     * does not use draws from none, as PHP rejects it.
     *
     * @param list<array{ClassInfo, list<string>, array<string, MemberEntry>}> $traits
     * @return array<int, list<TraitAlias>>
     */
    private function aliasesByTrait(ClassInfo $class, array $traits): array
    {
        $grouped = [];
        foreach ($class->traitAliases as $alias) {
            foreach ($traits as $index => [$trait, , $traitTable]) {
                $draws = $alias->trait === null
                    ? array_key_exists(MemberKind::Method->keyFor($alias->method), $traitTable)
                    : $alias->trait->equals($trait->name);
                if ($draws) {
                    $grouped[$index][] = $alias;
                    break;
                }
            }
        }

        return $grouped;
    }

    /**
     * Adds a member a trait brings in, keeping one $class already has unless
     * {@see MemberKind::traitReplacesExisting()} says otherwise.
     *
     * @param array<string, MemberEntry> $table
     */
    private function addTraitMember(
        array &$table,
        ClassInfo $class,
        MemberKind $kind,
        MemberInfoInterface $member,
    ): void {
        $key = $kind->keyFor($member->getName()->name);
        if (!array_key_exists($key, $table) || $kind->traitReplacesExisting($member)) {
            $table[$key] = new MemberEntry($member, $class);
        }
    }

    /**
     * The method an alias exposes on $class: the trait's method under the
     * alias's name, with the alias's visibility if it gives one.
     */
    private function aliased(MemberInfoInterface $source, TraitAlias $alias, ClassInfo $class): MethodInfo
    {
        assert($source instanceof MethodInfo, 'an alias adapts a method');

        return new MethodInfo(
            name: new MethodName($class->name, $alias->newName ?? $source->name->name),
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
     * Adds what $class inherits from its parent's table: each member it
     * lacks, unless {@see MemberKind::inheritsPrivate()}
     * drops a private one; a member it has takes the inherited one's root as
     * its prototype, as {@see MemberKind::inheritsPrototype()} allows.
     *
     * @param array<string, MemberEntry> $table
     * @param array<string, MemberEntry> $inherited
     */
    private function inherit(array &$table, array $inherited, MemberKind $kind): void
    {
        foreach ($inherited as $key => $entry) {
            if (!array_key_exists($key, $table)) {
                if ($kind->inheritsPrivate() || $entry->member->getVisibility() !== Visibility::Private) {
                    $table[$key] = $entry;
                }
                continue;
            }
            $root = $entry->rootEntry();
            if ($kind->inheritsPrototype($entry->member, $root->member)) {
                $table[$key] = $table[$key]->withPrototype($root);
            }
        }
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

    private function parentOf(ClassInfo $classInfo): ?ClassInfo
    {
        return $classInfo->parent === null ? null : $this->source->lookupClassLike($classInfo->parent);
    }

    /**
     * @return list<ClassInfo>
     */
    private function interfacesOf(ClassInfo $classInfo): array
    {
        $interfaces = [];
        foreach ($classInfo->interfaces as $name) {
            $interface = $this->source->lookupClassLike($name);
            if ($interface !== null) {
                $interfaces[] = $interface;
            }
        }

        return $interfaces;
    }

    /**
     * The class-likes a class-like is a subclass of directly: its parent and
     * its interfaces. Used traits are not (is_subclass_of in PHP).
     *
     * @return list<ClassInfo>
     */
    private function inheritedTypes(ClassInfo $classInfo): array
    {
        $parent = $this->parentOf($classInfo);

        return $parent === null ? $this->interfacesOf($classInfo) : [$parent, ...$this->interfacesOf($classInfo)];
    }
}
