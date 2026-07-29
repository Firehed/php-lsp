<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Cache;

use Firehed\PhpLsp\Cache\CacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CacheFactory::class)]
final class CacheFactoryTest extends TestCase
{
    public function testAHitReturnsTheStoredInstanceRatherThanACopy(): void
    {
        $cache = CacheFactory::inMemory();
        $value = new stdClass();

        $cache->set('key', $value);

        self::assertSame(
            $value,
            $cache->get('key'),
            'The cache must return the stored instance, not a clone, so callers keep object identity',
        );
    }
}
