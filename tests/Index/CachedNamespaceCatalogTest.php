<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Index\CachedNamespaceCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedNamespaceCatalog::class)]
class CachedNamespaceCatalogTest extends TestCase
{
    private CountingNamespaceCatalog $source;
    private CachedNamespaceCatalog $catalog;

    protected function setUp(): void
    {
        $this->source = new CountingNamespaceCatalog();
        $this->catalog = new CachedNamespaceCatalog($this->source, CacheFactory::inMemory());
    }

    public function testARepeatedLookupIsServedFromTheCache(): void
    {
        $first = $this->catalog->childrenOf('Psr\Log');
        $second = $this->catalog->childrenOf('Psr\Log');

        self::assertSame(
            1,
            $this->source->calls,
            'Completion re-queries on every keystroke; the same namespace must not be re-read each time',
        );
        self::assertEquals($first, $second, 'The cached answer is the same answer');
    }

    public function testDifferentNamespacesAreCachedSeparately(): void
    {
        $this->catalog->childrenOf('Psr\Log');
        $this->catalog->childrenOf('Psr\Http');

        self::assertSame(
            2,
            $this->source->calls,
            'Each namespace is resolved on its own; only what is visited is paid for',
        );
    }

    public function testLookupsAreCachedCaseInsensitively(): void
    {
        $this->catalog->childrenOf('Psr\Log');
        $this->catalog->childrenOf('psr\log');

        self::assertSame(
            1,
            $this->source->calls,
            'Namespaces are case-insensitive in PHP, so these are one namespace, not two',
        );
    }

    public function testInvalidateDropsCachedListingsSoTheNextLookupReReads(): void
    {
        $this->catalog->childrenOf('Psr\Log');

        $this->catalog->invalidate('file:///any/changed/File.php');
        $this->catalog->childrenOf('Psr\Log');

        self::assertSame(
            2,
            $this->source->calls,
            'invalidate must drop the cached listing so a changed directory is re-read (RFC 1 §5.3)',
        );
    }
}
