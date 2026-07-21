<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @extends RuleTestCase<RawInitializeCapabilitiesRule>
 */
#[CoversClass(RawInitializeCapabilitiesRule::class)]
class RawInitializeCapabilitiesRuleTest extends RuleTestCase
{
    private const string EXPECTED_MESSAGE = 'Raw initialize capabilities must not be read outside '
        . 'Firehed\PhpLsp\Capability; query SessionCapabilities instead (RFC 1 §4.8).';

    public function testReadingRawCapabilitiesElsewhereIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/reads-raw-capabilities.php'],
            [[self::EXPECTED_MESSAGE, 18]],
        );
    }

    public function testTheNegotiationPackageMayReadRawCapabilities(): void
    {
        $this->analyse([__DIR__ . '/data/negotiates-raw-capabilities.php'], []);
    }

    protected function getRule(): Rule
    {
        return new RawInitializeCapabilitiesRule();
    }
}
