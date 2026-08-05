<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolLocator;
use Firehed\PhpLsp\Resolution\NameKind;

/**
 * Locates a declaration through Composer's autoload configuration.
 *
 * Only class-likes are reachable this way, and that is a property of Composer's
 * data rather than a gap here: PSR-4, PSR-0 and the classmap all map a class name
 * to a file, so a lookup is arithmetic on the name and no file is read. Nothing
 * about the name `Foo\bar` says which file declares it as a function or a constant;
 * those declarations are reachable only in the `autoload.files` set, through an
 * index derived by parsing it (Plan 0002 §3b).
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
