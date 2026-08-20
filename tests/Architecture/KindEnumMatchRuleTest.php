<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<KindEnumMatchRule>
 */
class KindEnumMatchRuleTest extends RuleTestCase
{
    public function testMatchOnKindEnumIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/match-on-kind-enum.php'],
            [
                [
                    'match on NameKind branches per symbol kind; use predicates (RFC 1 §4.5).',
                    17,
                ],
            ],
        );
    }

    public function testKindEnumOwnFileAllowed(): void
    {
        $this->analyse([__DIR__ . '/../../src/Domain/NameKind.php'], []);
    }

    protected function getRule(): Rule
    {
        return new KindEnumMatchRule();
    }
}
