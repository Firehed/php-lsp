<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Protocol\ServerInfo::class => fn () => new Protocol\ServerInfo('php-lsp', '0.1.0'),

    // Listed explicitly: the listener list is an optional array, which
    // autowiring would fill with its empty default.
    Handler\LifecycleHandler::class => fn (TC $c) => new Handler\LifecycleHandler(
        $c->get(Capability\CapabilityNegotiator::class),
        [$c->get(Capability\WatchedFilesRegistrar::class)],
    ),

    Server::class => function (TC $c) {
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            throw new \LogicException('Unable to determine project root: getcwd() failed');
        }
        return Server::forProject($c, $projectRoot);
    },
];
