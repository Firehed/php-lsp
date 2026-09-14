<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

return [
    Protocol\ServerInfo::class => fn () => new Protocol\ServerInfo('php-lsp', '0.1.0'),

    Server::class => fn ($c) => Server::forProject(
        $c->get(Transport\TransportInterface::class),
        $c->get(Protocol\ServerInfo::class),
    ),
];
