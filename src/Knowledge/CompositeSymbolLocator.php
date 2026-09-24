<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;

/**
 * Chains the routes to a declaration, in the order the runtime consults them.
 *
 * Composer addresses class-likes by arithmetic on the name and everything in the
 * `autoload.files` set by no name at all, so the two are separate locators rather
 * than one, each responsible for one mechanism (Plan 0002 §3). The runtime
 * requires every files entry before the autoloader is ever asked, so the derived
 * index answers first and the maps answer what it does not declare.
 */
final class CompositeSymbolLocator implements SymbolLocatorInterface
{
    /**
     * @param list<SymbolLocatorInterface> $locators In precedence order
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
