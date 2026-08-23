<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<DynamicClassReferenceRule>
 */
class DynamicClassReferenceRuleTest extends RuleTestCase
{
    public function testEveryComputedClassNameIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/dynamic-class-reference.php'],
            [
                ['new on a computed class name; name the class literally (RFC 1 §4.5).', 26],
                ['instanceof on a computed class name; name the class literally (RFC 1 §4.5).', 31],
                ['class constant fetch on a computed class name; name the class literally (RFC 1 §4.5).', 36],
                ['class constant fetch on a computed class name; name the class literally (RFC 1 §4.5).', 41],
                ['static call on a computed class name; name the class literally (RFC 1 §4.5).', 46],
                ['static property fetch on a computed class name; name the class literally (RFC 1 §4.5).', 51],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new DynamicClassReferenceRule();
    }
}
