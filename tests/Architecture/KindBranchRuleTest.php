<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<KindBranchRule>
 */
class KindBranchRuleTest extends RuleTestCase
{
    public function testEveryBranchFormOnEveryKindEnumIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/branches-on-kind.php'],
            [
                ['match on NameKind branches per symbol kind; use predicates.', 23],
                ['switch on ClassKind branches per symbol kind; use predicates.', 32],
                ['=== on NameKind branches per symbol kind; use predicates.', 42],
                ['!== on MemberKind branches per symbol kind; use predicates.', 47],
                ['== on SymbolKind branches per symbol kind; use predicates.', 52],
                ['!= on MemberAccessKind branches per symbol kind; use predicates.', 57],
                ['in_array on CompletionItemKind branches per symbol kind; use predicates.', 65],
                ['in_array on SymbolKind branches per symbol kind; use predicates.', 73],
                ['array_search on MemberKind branches per symbol kind; use predicates.', 81],
                ['=== on CompletionItemKind branches per symbol kind; use predicates.', 86],
                ['=== on SymbolKind branches per symbol kind; use predicates.', 91],
                ['match on MemberFilter branches per symbol kind; use predicates.', 96],
            ],
        );
    }

    public function testKindEnumsMayBranchOnThemselves(): void
    {
        $this->analyse(
            [
                __DIR__ . '/../../src/Domain/NameKind.php',
                __DIR__ . '/../../src/Domain/MemberKind.php',
            ],
            [],
        );
    }

    protected function getRule(): Rule
    {
        return new KindBranchRule();
    }
}
