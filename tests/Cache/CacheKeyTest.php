<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Cache;

use Firehed\PhpLsp\Cache\CacheKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheKey::class)]
final class CacheKeyTest extends TestCase
{
    public function testDerivedKeyContainsNoPsr16ReservedCharacters(): void
    {
        // A backslash-laden identifier is exactly what a raw FQN or namespace is.
        $key = CacheKey::from('psr\\http\\message\\requestinterface');

        self::assertFalse(
            strpbrk($key, '{}()/\\@:'),
            'PSR-16 forbids these characters in a key, so the derived key must avoid them',
        );
    }

    public function testDerivationIsDeterministic(): void
    {
        self::assertSame(
            CacheKey::from('app\\service'),
            CacheKey::from('app\\service'),
            'The same identifier must resolve to the same cache entry',
        );
    }

    public function testDistinctIdentifiersDeriveDistinctKeys(): void
    {
        self::assertNotSame(
            CacheKey::from('app\\service'),
            CacheKey::from('app\\other'),
            'Different identifiers must not collide onto one cache entry',
        );
    }

    public function testKeyStaysWithinThePsr16LengthGuarantee(): void
    {
        $longIdentifier = str_repeat('firehed\\phplsp\\', 20);

        self::assertLessThanOrEqual(
            64,
            strlen(CacheKey::from($longIdentifier)),
            'PSR-16 only guarantees support for keys up to 64 characters',
        );
    }
}
