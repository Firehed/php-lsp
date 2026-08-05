<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Cache\Invalidatable;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * Chains the routes to a declaration, in order of cost and authority.
 *
 * Composer addresses class-likes by arithmetic on the name and everything in the
 * `autoload.files` set by no name at all, so the two are separate locators rather
 * than one: the map lookup is cheap and answers most names, and the derived index
 * covers what the maps structurally cannot (Plan 0002 §3). Chaining keeps the
 * cheaper route first and leaves each locator responsible for one mechanism.
 */
final class CompositeSymbolLocator implements SymbolLocator, Invalidatable
{
    /**
     * @param list<SymbolLocator> $locators In precedence order
     */
    public function __construct(
        private readonly array $locators,
    ) {
    }

    /**
     * Fans out to the members that hold derived state. A locator that resolves by
     * arithmetic alone holds none and does not implement {@see Invalidatable}.
     */
    public function invalidate(string $uri): void
    {
        foreach ($this->locators as $locator) {
            if ($locator instanceof Invalidatable) {
                $locator->invalidate($uri);
            }
        }
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
