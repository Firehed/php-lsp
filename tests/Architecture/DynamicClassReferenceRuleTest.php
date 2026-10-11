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
    public function testEveryComputedClasslikeNameIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/dynamic-class-reference.php'],
            [
                ['new on a computed class name; name the class literally.', 26],
                ['instanceof on a computed class name; name the class literally.', 31],
                ['class constant fetch on a computed class name; name the class literally.', 36],
                ['class constant fetch on a computed class name; name the class literally.', 41],
                ['static call on a computed class name; name the class literally.', 46],
                ['static property fetch on a computed class name; name the class literally.', 51],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new DynamicClassReferenceRule();
    }
}
