<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\PhpLsp\Index\DocumentIndexer;
use Firehed\PhpLsp\Index\NamespaceCatalog;
use Firehed\PhpLsp\Index\SymbolExtractor;
use Firehed\PhpLsp\Index\SymbolIndex;
use Firehed\PhpLsp\Knowledge\DelegatingSymbolSource;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\DefaultClassInfoFactory;

/**
 * Builds the production {@see DelegatingSymbolSource} facade over a test's existing
 * class repository, so a consumer that reads through the {@see \Firehed\PhpLsp\Knowledge\SymbolSource}
 * seam (e.g. {@see \Firehed\PhpLsp\Resolution\SymbolResolver}) can be wired without
 * every test class re-assembling the six collaborators by hand.
 *
 * The enumeration collaborators (index, indexer, catalog) are inert here: a consumer
 * that only needs class-like lookup or the subtype query never reaches them, so a
 * throwaway index and a stubbed catalog are sufficient. A test exercising namespace
 * enumeration or the write path builds its own facade with real ones instead.
 */
trait BuildsSymbolSourceTrait
{
    protected function symbolSourceFor(
        ClassRepository $repository,
        ParserService $parser,
    ): DelegatingSymbolSource {
        $index = new SymbolIndex();

        return new DelegatingSymbolSource(
            $repository,
            $index,
            self::createStub(NamespaceCatalog::class),
            new DocumentIndexer($parser, new SymbolExtractor(), $index),
            new DefaultClassInfoFactory(),
            $parser,
        );
    }
}
