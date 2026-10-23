<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Knowledge\ComposerAutoloadMapReader::class => function () {
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            throw new \LogicException('Unable to determine project root: getcwd() failed');
        }
        return new Knowledge\ComposerAutoloadMapReader($projectRoot);
    },

    Knowledge\DeclarationScanner::class,
    Knowledge\ParsedDeclarationSource::class,
    // Working-set target: a typical editing session touches fewer files than
    // this, so nothing evicts. Not derived from measurement; picked as a
    // comfortable bound above the observed working set.
    Knowledge\DeclarationSourceInterface::class => fn (TC $c) => new Knowledge\CachingDeclarationSource(
        $c->get(Knowledge\ParsedDeclarationSource::class),
        Cache\CacheFactory::inMemory(maxItems: 2000),
    ),

    Knowledge\OpenDocumentBackend::class,
    Knowledge\AutoloadFilesBackend::class,
    Knowledge\ComposerMapBackend::class,
    Knowledge\BuiltinBackend::class,
    // Composer requires the autoload.files entries before the autoloader is
    // ever asked, so a name declared there wins over the maps.
    Knowledge\CompositeSymbolSource::class => fn (TC $c) => new Knowledge\CompositeSymbolSource(
        $c->get(Knowledge\OpenDocumentBackend::class),
        $c->get(Knowledge\AutoloadFilesBackend::class),
        $c->get(Knowledge\ComposerMapBackend::class),
        $c->get(Knowledge\BuiltinBackend::class),
    ),
    Knowledge\SymbolSourceInterface::class => Knowledge\CompositeSymbolSource::class,
    Knowledge\SymbolSinkInterface::class => Knowledge\OpenDocumentBackend::class,

    Knowledge\CompositeInvalidatable::class,
    Cache\InvalidatableInterface::class => Knowledge\CompositeInvalidatable::class,
];
