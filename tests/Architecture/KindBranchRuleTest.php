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
                ['match on NameKind branches per symbol kind; use predicates (RFC 1 §4.5).', 24],
                ['switch on ClassKind branches per symbol kind; use predicates (RFC 1 §4.5).', 33],
                ['=== on NameKind branches per symbol kind; use predicates (RFC 1 §4.5).', 43],
                ['!== on MemberKind branches per symbol kind; use predicates (RFC 1 §4.5).', 48],
                ['== on SymbolKind branches per symbol kind; use predicates (RFC 1 §4.5).', 53],
                ['!= on MemberAccessKind branches per symbol kind; use predicates (RFC 1 §4.5).', 58],
                ['in_array on CompletionItemKind branches per symbol kind; use predicates (RFC 1 §4.5).', 66],
                ['match on MemberFilter branches per symbol kind; use predicates (RFC 1 §4.5).', 71],
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
