<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<FormatComparisonRule>
 */
class FormatComparisonRuleTest extends RuleTestCase
{
    public function testFormatComparisonIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/compares-format.php'],
            [
                ['comparing format() output branches on display representation; use Type predicates (RFC 1 §4.5).', 17],
                ['comparing format() output branches on display representation; use Type predicates (RFC 1 §4.5).', 22],
                ['comparing format() output branches on display representation; use Type predicates (RFC 1 §4.5).', 27],
                ['comparing format() output branches on display representation; use Type predicates (RFC 1 §4.5).', 32],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new FormatComparisonRule();
    }
}
