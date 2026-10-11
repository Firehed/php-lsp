<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
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
            'Every SessionCapabilities property must have a default value. '
            . 'Missing defaults: ' . implode(', ', $missingDefaults),
        );
    }

    public function testDefaultSessionCapabilitiesIsConstructable(): void
    {
        $capabilities = new SessionCapabilities();

        self::assertInstanceOf(
            SessionCapabilities::class,
            $capabilities,
            'SessionCapabilities must be constructable with no arguments',
        );
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
                    'Property %s has a null default; explicit safe defaults are required',
                    $property->getName(),
                ),
            );
        }
    }
}
