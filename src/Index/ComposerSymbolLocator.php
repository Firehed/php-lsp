<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * Locates a declaration through Composer's autoload maps, which address class-likes
 * and nothing else: PSR-4, PSR-0 and the classmap all map a class name to a file, so
 * a lookup is arithmetic on the name. Functions and constants are reachable only by
 * parsing the `autoload.files` set, which {@see AutoloadFilesLocator} derives an
 * index from; the two are chained by
 * {@see \Firehed\PhpLsp\Knowledge\CompositeSymbolLocator}.
 */
final class ComposerSymbolLocator implements SymbolLocator
{
    public function __construct(
        private readonly ComposerAutoloadMap $map,
    ) {
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        if ($kind !== NameKind::ClassLike) {
            return null;
        }

        $file = $this->map->classLoader()->findFile($name->fullyQualifiedName());

        return $file !== false ? $file : null;
    }
}
