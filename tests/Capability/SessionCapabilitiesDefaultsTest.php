<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * RFC 1 §5.4: "The absence of a client capability MUST resolve to a safe default
 * that is the value's own default state, not a special-cased branch at the point
 * of use."
 *
 * This test verifies that every SessionCapabilities property has a default value
 * in the constructor, so a minimal client (one that declares no capabilities) is
 * served by the defaults without a branch.
 *
 * If you add a new capability property, you MUST also provide a default value
 * in the constructor. This test will fail if you add a required parameter.
 */
#[CoversClass(SessionCapabilities::class)]
final class SessionCapabilitiesDefaultsTest extends TestCase
{
    public function testEveryCapabilityHasADefaultValue(): void
    {
        $reflection = new ReflectionClass(SessionCapabilities::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor, 'SessionCapabilities must have a constructor');

        $parameters = $constructor->getParameters();
        $missingDefaults = [];

        foreach ($parameters as $param) {
            if (!$param->isDefaultValueAvailable()) {
                $missingDefaults[] = $param->getName();
            }
        }

        self::assertSame(
            [],
            $missingDefaults,
            'Every SessionCapabilities property must have a default value (RFC 1 §5.4). '
            . 'Missing defaults: ' . implode(', ', $missingDefaults),
        );
    }

    public function testDefaultSessionCapabilitiesIsConstructable(): void
    {
        $capabilities = new SessionCapabilities();

        self::assertNotNull($capabilities, 'SessionCapabilities must be constructable with no arguments');
    }

    public function testAllDefaultsAreExplicit(): void
    {
        $capabilities = new SessionCapabilities();
        $reflection = new ReflectionClass($capabilities);

        foreach ($reflection->getProperties() as $property) {
            $value = $property->getValue($capabilities);

            self::assertNotNull(
                $value,
                sprintf(
                    'Property %s has a null default; RFC 1 §5.4 requires explicit safe defaults',
                    $property->getName(),
                ),
            );
        }
    }
}
