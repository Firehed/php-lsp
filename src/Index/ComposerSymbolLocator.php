<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Index;

use Composer\Autoload\ClassLoader;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Knowledge\SymbolLocatorInterface;

/**
 * Locates a declaration through Composer's autoload maps, which address class-likes
 * and nothing else: PSR-4, PSR-0 and the classmap all map a class name to a file, so
 * a lookup is arithmetic on the name. Functions and constants are reachable only by
 * parsing the `autoload.files` set, which {@see AutoloadFilesLocator} derives an
 * index from; the two are chained by
 * {@see \Firehed\PhpLsp\Knowledge\CompositeSymbolLocator}.
 *
 * The lookup runs on Composer's own {@see ClassLoader} so the runtime's precedence
 * applies verbatim. The loader is built from the map on every call rather than held:
 * it remembers every name it fails to find for its whole lifetime and has no reset,
 * so a held instance would never see a file created after the first miss. Memoizing
 * hits is the cache seam's job, above this locator.
 */
final class ComposerSymbolLocator implements SymbolLocatorInterface
{
    public function __construct(
        private readonly ComposerAutoloadMap $map,
    ) {
    }

    public function locate(QualifiedName $name, NameKind $kind): ?string
    {
        if (!$kind->isClassLike()) {
            return null;
        }

        $file = $this->loader()->findFile($name->fullyQualifiedName());

        return $file !== false ? $file : null;
    }

    private function loader(): ClassLoader
    {
        $loader = new ClassLoader();
        foreach ($this->map->psr4Prefixes() as $prefix => $directories) {
            $loader->setPsr4($prefix, $directories);
        }
        foreach ($this->map->psr0Prefixes() as $prefix => $directories) {
            $loader->set($prefix, $directories);
        }
        $loader->addClassMap($this->map->classMap());

        return $loader;
    }
}
