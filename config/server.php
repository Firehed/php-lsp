<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Protocol\ServerInfo::class => fn () => new Protocol\ServerInfo('php-lsp', '0.1.0'),

    Server::class => function (TC $c) {
        return Server::forProject(
            $c->get(Transport\TransportInterface::class),
            $c->get(Protocol\ServerInfo::class),
        );
    },
];
