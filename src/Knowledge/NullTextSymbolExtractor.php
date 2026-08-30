<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

/**
 * A {@see TextSymbolExtractor} that produces nothing — the sink's tier-3 fallback is
 * inert. Used by test wiring that does not exercise first-open-broken behavior and
 * by any caller that has no regex primitive to inject (the Knowledge layer cannot
 * depend on Resolution, where the real extractor lives).
 */
final class NullTextSymbolExtractor implements TextSymbolExtractor
{
    public function extract(string $content, string $filePath): array
    {
        return [];
    }
}
