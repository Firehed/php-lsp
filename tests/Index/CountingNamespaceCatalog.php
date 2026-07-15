<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Index;

use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\NamespaceContents;

/**
 * Records how often it is asked about a namespace, so that caching and laziness
 * can be asserted on rather than assumed.
 */
final class CountingNamespaceCatalog implements NamespaceCatalog
{
    public int $calls = 0;

    public function childrenOf(string $namespace): NamespaceContents
    {
        $this->calls++;

        return new NamespaceContents([$namespace . '\Child'], []);
    }
}
