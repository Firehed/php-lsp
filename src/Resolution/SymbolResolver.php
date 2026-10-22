<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\ResolvedMemberInterface;
use Firehed\PhpLsp\Domain\TypeInterface;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Knowledge\SymbolSourceInterface;
use Firehed\PhpLsp\Parser\NodeLocator\NodeLocatorInterface;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;
use Firehed\PhpLsp\Repository\MemberResolverInterface;
use Throwable;

/**
 * Centralizes symbol resolution for LSP handlers.
 *
 * This class provides a single entry point for resolving symbols at cursor
 * positions, eliminating the M×N problem where M handlers each independently
 * implement resolution logic for N node types.
 *
 * FUTURE: Workspace queries (requires index)
 * - findReferences(SymbolIdentity $symbol, ?Scope $scope = null): array<Location>
 * - findImplementations(ClasslikeName $interface): array<ClassInfo>
 * - findSubtypes(ClasslikeName $class): array<ClassInfo>
 * - findSupertypes(ClasslikeName $class): array<ClassInfo>
 *
 * FUTURE: Call hierarchy
 * - getIncomingCalls(ResolvedCallableInterface $callable): array<CallHierarchyItem>
 * - getOutgoingCalls(ResolvedCallableInterface $callable): array<CallHierarchyItem>
 *
 * FUTURE: Batch operations (for SemanticTokens)
 * - resolveAllSymbols(Document $document): array<ResolvedToken>
 */
final class SymbolResolver implements CodeResolverInterface
{
    private readonly MemberAccessDetector $memberAccessDetector;

    public function __construct(
        private readonly SyntaxSourceInterface $parser,
        private readonly NodeLocatorInterface $locator,
        private readonly SymbolSourceInterface $symbolSource,
        private readonly MemberResolverInterface $memberResolver,
        private readonly ExpressionResolverInterface $expressions,
    ) {
        $this->memberAccessDetector = new MemberAccessDetector(
            $memberResolver,
            $locator,
            $expressions,
        );
    }

    /**
     * Get members accessible on a type.
     * Used by: Completion (after -> or ::)
     *
     * For instance access: returns methods and properties.
     * For static access: also includes constants and enum cases.
     *
     * @return list<ResolvedMemberInterface>
     */
    public function getAccessibleMembers(
        TextDocument $document,
        TypeInterface $type,
        Visibility $minVisibility,
        MemberFilter $filter = MemberFilter::Instance,
    ): array {
        $classNames = $type->getResolvableClasslikeNames();
        $members = [];
        // Keyed by MemberFilter case name so the choice reads as a lookup, not
        // an enum comparison the kind-branch rule flags.
        $includesStatic = ['All' => true, 'Static' => true, 'Instance' => false];
        $includeStatic = $includesStatic[$filter->name];

        foreach ($classNames as $className) {
            foreach ($this->getMembersForClass($className, $minVisibility, $filter, $includeStatic) as $member) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * Get members for a single class using AST/reflection.
     *
     * One loop over the kinds a position admits, so no kind can drift onto a
     * different walk. The *Info metadata objects implement {@see ResolvedMemberInterface}
     * directly, so no wrapper is built per member.
     *
     * @return list<ResolvedMemberInterface>
     */
    private function getMembersForClass(
        ClasslikeName $className,
        Visibility $minVisibility,
        MemberFilter $filter,
        bool $includeStatic,
    ): array {
        $kinds = $includeStatic
            ? [MemberKind::Method, MemberKind::Property, MemberKind::Constant, MemberKind::EnumCase]
            : [MemberKind::Method, MemberKind::Property];

        $members = [];
        foreach ($kinds as $kind) {
            foreach ($this->memberResolver->getMembersOfKind($className, $kind, $minVisibility, $filter) as $member) {
                $members[] = $member;
            }
        }
        return $members;
    }

    /**
     * Check if a name resolves to a real class-like.
     * Returns false for unknown names: a phantom must be dropped, so this cannot
     * share the optimism of the position predicates.
     */
    public function isClassLike(ClasslikeName $className): bool
    {
        return $this->symbolSource->lookupClassLike($className) !== null;
    }

    /**
     * Check if a class can be instantiated with `new`.
     * Returns true for unknown classes (optimistic filtering).
     */
    public function isInstantiable(ClasslikeName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return true;
        }
        return !$classInfo->isAbstract && $classInfo->isClass();
    }

    public function isValidTypeHint(ClasslikeName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return true;
        }
        return !$classInfo->isTrait();
    }

    /**
     * Check if a class-like is an interface.
     * Returns false for unknown classes: an implements list must only offer
     * confirmed interfaces.
     */
    public function isInterface(ClasslikeName $className): bool
    {
        return $this->memberResolver->isInterface($className);
    }

    public function isTrait(ClasslikeName $className): bool
    {
        return $this->memberResolver->isTrait($className);
    }

    /**
     * Check if a class-like can be extended by a class.
     * True for non-final classes (abstract included); false for final classes,
     * interfaces, traits, enums, and unknown classes: an extends clause must
     * only offer confirmed base classes.
     */
    public function isExtendableClass(ClasslikeName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isClass() && !$classInfo->isFinal;
    }

    /**
     * Check if a class-like can be caught.
     * True for `Throwable` itself and anything that extends or implements it
     * transitively (classes and interfaces alike). Returns false for unknown
     * classes: a catch clause must only offer confirmed Throwables.
     */
    public function isThrowable(ClasslikeName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }

        $throwable = ClasslikeName::fromFullyQualified(Throwable::class);
        if ($classInfo->name->equals($throwable)) {
            return true;
        }
        return $this->memberResolver->isSubclassOf($className, $throwable);
    }

    /**
     * Check if a class is a PHP attribute.
     * Returns false for unknown classes: an attribute position must only offer
     * confirmed attributes.
     */
    public function isAttribute(ClasslikeName $className): bool
    {
        $classInfo = $this->symbolSource->lookupClassLike($className);
        if ($classInfo === null) {
            return false;
        }
        return $classInfo->isAttribute;
    }

    /**
     * Get member access context at position.
     * Used by: Completion (after -> or ::)
     */
    public function getMemberAccessContext(
        TextDocument $document,
        int $line,
        int $character,
    ): ?MemberAccessContext {
        return $this->memberAccessDetector->detect($this->parser->parse($document), $line, $character);
    }
}
