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
                ['match on NameKind branches per symbol kind; use predicates (RFC 1 §4.5).', 23],
                ['switch on ClassKind branches per symbol kind; use predicates (RFC 1 §4.5).', 32],
                ['=== on NameKind branches per symbol kind; use predicates (RFC 1 §4.5).', 42],
                ['!== on MemberKind branches per symbol kind; use predicates (RFC 1 §4.5).', 47],
                ['== on SymbolKind branches per symbol kind; use predicates (RFC 1 §4.5).', 52],
                ['!= on MemberAccessKind branches per symbol kind; use predicates (RFC 1 §4.5).', 57],
                ['in_array on CompletionItemKind branches per symbol kind; use predicates (RFC 1 §4.5).', 65],
                ['match on MemberFilter branches per symbol kind; use predicates (RFC 1 §4.5).', 70],
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
