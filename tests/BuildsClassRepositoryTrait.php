<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Cache\CacheFactory;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassInfoFactory;
use Firehed\PhpLsp\Repository\ClassLocator;
use Firehed\PhpLsp\Repository\DefaultClassRepository;

/**
 * Constructs {@see DefaultClassRepository} for tests through one seam.
 *
 * Tests pass the collaborators they customise (factory, locator, parser); the
 * repository's infrastructure dependencies — currently the PSR-16 cache — are
 * supplied here with a working default. A new infrastructure dependency on the
 * repository is then a change to this one helper, not to every test that builds
 * one. Production wiring lives in the composition roots (Server), not here.
 */
trait BuildsClassRepositoryTrait
{
    private function buildClassRepository(
        ClassInfoFactory $factory,
        ClassLocator $locator,
        ParserService $parser,
    ): DefaultClassRepository {
        return new DefaultClassRepository(
            $factory,
            $locator,
            $parser,
            CacheFactory::inMemory(),
        );
    }
}
