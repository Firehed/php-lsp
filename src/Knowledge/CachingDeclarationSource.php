<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\CacheKey;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\DeclaredSymbol;
use Firehed\PhpLsp\Domain\FileUri;
use Psr\SimpleCache\CacheInterface;

/**
 * Keyed by the file and its text together, so an entry describes exactly one
 * state of one file and never goes stale: changed text is a different key.
 */
final readonly class CachingDeclarationSource implements DeclarationSourceInterface
{
    public function __construct(
        private DeclarationSourceInterface $inner,
        private CacheInterface $cache,
    ) {
    }

    public function declarationsIn(TextDocument $document): array
    {
        $key = CacheKey::from(FileUri::toPath($document->uri) . "\0" . $document->getContent());

        /** @var list<DeclaredSymbol>|null $declared */
        $declared = $this->cache->get($key);
        if ($declared === null) {
            $declared = $this->inner->declarationsIn($document);
            $this->cache->set($key, $declared);
        }

        return $declared;
    }
}
