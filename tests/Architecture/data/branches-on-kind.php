<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Completion\CompletionItemKind;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\MemberFilter;
use Firehed\PhpLsp\Domain\MemberKind;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Index\SymbolKind;
use Firehed\PhpLsp\Resolution\MemberAccessKind;

/**
 * A consumer deciding behavior by branching on a symbol-kind enum, in every
 * syntactic form, which RFC 1 §4.5 forbids.
 */
final class BranchesOnKind
{
    public function matchOnKind(NameKind $kind): string
    {
        return match ($kind) {
            NameKind::ClassLike => 'class',
            NameKind::Function_ => 'function',
            NameKind::Constant => 'constant',
        };
    }

    public function switchOnKind(ClassKind $kind): string
    {
        switch ($kind) {
            case ClassKind::Trait_:
                return 'trait';
            default:
                return 'other';
        }
    }

    public function identicalToCase(NameKind $kind): bool
    {
        return $kind === NameKind::ClassLike;
    }

    public function caseNotIdenticalTo(MemberKind $kind): bool
    {
        return MemberKind::Method !== $kind;
    }

    public function looselyEqual(SymbolKind $a, SymbolKind $b): bool
    {
        return $a == $b;
    }

    public function looselyUnequal(?MemberAccessKind $kind): bool
    {
        return $kind != MemberAccessKind::Parent;
    }

    /**
     * @param list<CompletionItemKind> $wanted
     */
    public function inArray(CompletionItemKind $kind, array $wanted): bool
    {
        return in_array($kind, $wanted, true);
    }

    /**
     * @param list<SymbolKind> $wanted
     */
    public function inArrayNamedArguments(SymbolKind $kind, array $wanted): bool
    {
        return in_array(strict: true, haystack: $wanted, needle: $kind);
    }

    /**
     * @param list<MemberKind> $wanted
     */
    public function arraySearch(MemberKind $kind, array $wanted): int|string|false
    {
        return array_search($kind, $wanted, true);
    }

    public function backingValue(CompletionItemKind $kind, int $wireValue): bool
    {
        return $kind->value === $wireValue;
    }

    public function caseName(SymbolKind $kind, string $label): bool
    {
        return $kind->name === $label;
    }

    public function matchOnFilter(MemberFilter $filter): bool
    {
        return match ($filter) {
            MemberFilter::All => true,
            MemberFilter::Static, MemberFilter::Instance => false,
        };
    }

    public function stringComparisonIsNotABranch(string $a, string $b): bool
    {
        return MemberKind::Method->normalize($a) === MemberKind::Method->normalize($b);
    }
}
