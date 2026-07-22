<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Capability;

use Firehed\PhpLsp\Capability\SessionCapabilities;
use Firehed\PhpLsp\Protocol\MarkupKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Resolving a client's declared capabilities into this value is
 * `CapabilityNegotiator`'s job, and is covered by `CapabilityNegotiatorTest`;
 * the value itself only has to carry safe defaults.
 */
#[CoversClass(SessionCapabilities::class)]
class SessionCapabilitiesTest extends TestCase
{
    public function testDefaultsAreTheSafeValues(): void
    {
        $capabilities = new SessionCapabilities();

        self::assertSame(
            MarkupKind::PlainText,
            $capabilities->hoverMarkupKind,
            'plaintext is the only markup every client renders, so it is the safe default',
        );
        self::assertFalse(
            $capabilities->snippetSupport,
            'snippet syntax is inserted literally by a client that cannot expand it',
        );
    }
}
