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

    Knowledge\KnowledgeStack::class => fn (TC $c) => Knowledge\KnowledgeStack::forProject(
        $c->get(Knowledge\ComposerAutoloadMapReader::class),
        $c->get(Parser\SyntaxSource\SyntaxSourceInterface::class),
        $c->get(Document\DocumentSourceInterface::class),
    ),
    Knowledge\SymbolSourceInterface::class => fn (TC $c) => $c->get(Knowledge\KnowledgeStack::class)->source,
    Knowledge\SymbolSinkInterface::class => fn (TC $c) => $c->get(Knowledge\KnowledgeStack::class)->sink,
    Cache\InvalidatableInterface::class => fn (TC $c) => $c->get(Knowledge\KnowledgeStack::class)->invalidator,
];
