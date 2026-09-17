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
                ['new ClasslikeType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 20],
                ['new PrimitiveType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 25],
                ['new ClasslikeType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 30],
                ['new PrimitiveType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 30],
                ['new UnionType is confined to TypeFactory; use TypeFactory methods instead (RFC 1 §4.6).', 30],
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
