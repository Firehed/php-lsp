<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Protocol\ServerInfo::class => fn () => new Protocol\ServerInfo('php-lsp', '0.1.0'),

    Server::class => function (TC $c) {
        // return Server::forProject(
        //     $c->get(Transport\TransportInterface::class),
        //     $c->get(Protocol\ServerInfo::class),
        // );

        $handlers = [
            $c->get(Handler\TextDocumentSyncHandler::class),
            $c->get(Handler\DidChangeWatchedFilesHandler::class),
            $c->get(Handler\DefinitionHandler::class),
            $c->get(Handler\HoverHandler::class),
            $c->get(Handler\SignatureHelpHandler::class),
            $c->get(Handler\CompletionHandler::class),
        ];

        return new Server(
            transport: $c->get(Transport\TransportInterface::class),
            lifecycleHandler: $c->get(Handler\LifecycleHandler::class),
            handlers: $handlers,
            messageScope: $c->get(Parser\SyntaxSource\MessageScopedInterface::class),
        );
    },

    Document\DocumentManager::class,
];
