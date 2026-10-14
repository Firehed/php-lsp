<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Firehed\Container\TypedContainerInterface as TC;

return [
    Protocol\ServerInfo::class => fn () => new Protocol\ServerInfo('php-lsp', '0.1.0'),

    Handler\CompletionHandler::class,
    Handler\DefinitionHandler::class,
    Handler\DidChangeWatchedFilesHandler::class,
    Handler\HoverHandler::class,
    Handler\LifecycleHandler::class,
    Handler\SignatureHelpHandler::class,
    Handler\TextDocumentSyncHandler::class,

    Server::class => fn (TC $c) => Server::forProject($c),
];
