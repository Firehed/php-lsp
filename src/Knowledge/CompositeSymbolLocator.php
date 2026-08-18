<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * Chains the routes to a declaration, in order of cost and authority.
 *
 * Composer addresses class-likes by arithmetic on the name and everything in the
 * `autoload.files` set by no name at all, so the two are separate locators rather
 * than one: the map lookup is cheap and answers most names, and the derived index
 * covers what the maps structurally cannot (Plan 0002 §3). Chaining keeps the
 * cheaper route first and leaves each locator responsible for one mechanism.
 */
final class CompositeSymbolLocator implements SymbolLocator
{
    /**
     * @param list<SymbolLocator> $locators In precedence order
     */
    public function __construct(
        private readonly array $locators,
    ) {
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        foreach ($this->locators as $locator) {
            $path = $locator->locate($name, $kind);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }
}
