<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Resolution\NameKind;
use Firehed\PhpLsp\Utility\NamespacePath;

/**
 * A symbol discovered in a namespace.
 *
 * The kind is deliberately coarse — the three categories that participate in
 * name resolution, no finer. Discovery cannot always know more: a PSR-4
 * directory listing yields `LoggerInterface.php` without revealing whether it
 * declares an interface, a class, or a trait, and finding out means parsing it.
 *
 * That is not a loss, because it is not discovery's decision to make. Whether a
 * candidate is valid in a given position (an interface after `implements`, a
 * non-final class after `extends`) is already answered by the `CodeResolver`
 * predicates, which resolve through the caching `ClassRepository`. Discovery
 * says what exists and where; resolution says what it is.
 */
final readonly class CatalogSymbol
{
    public function __construct(
        public string $fullyQualifiedName,
        public NameKind $kind,
    ) {
    }

    public function shortName(): string
    {
        return NamespacePath::shortNameOf($this->fullyQualifiedName);
    }
}
