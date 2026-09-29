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

    public function testABoundedCacheDropsTheEntryUsedLeastRecently(): void
    {
        $cache = CacheFactory::inMemory(maxItems: 2);

        $cache->set('first', 1);
        $cache->set('second', 2);
        $cache->get('first');
        $cache->set('third', 3);

        self::assertTrue($cache->has('first'), 'an entry read since it was written is kept');
        self::assertFalse($cache->has('second'), 'the entry used least recently makes room');
        self::assertTrue($cache->has('third'), 'the newest entry is kept');
    }
}
