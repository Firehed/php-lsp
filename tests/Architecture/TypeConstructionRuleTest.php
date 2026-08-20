<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<TypeConstructionRule>
 */
class TypeConstructionRuleTest extends RuleTestCase
{
    public function testConstructingTypeOutsideFactoryIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/constructs-type-outside-factory.php'],
            [
                ['new ClassName is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 19],
                ['new PrimitiveType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 24],
                ['new ClassName is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 29],
                ['new PrimitiveType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 29],
                ['new UnionType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 29],
            ],
        );
    }

    public function testTypeFactoryMayConstructTypes(): void
    {
        $this->analyse([__DIR__ . '/../../src/Domain/TypeFactory.php'], []);
    }

    protected function getRule(): Rule
    {
        return new TypeConstructionRule();
    }
}
