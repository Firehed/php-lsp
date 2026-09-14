<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\Container\TypedContainerInterface as TC;
use Firehed\PhpLsp\Capability\CapabilityNegotiator;
use Firehed\PhpLsp\Capability\WatchedFilesRegistrar;

return [
    TextDocumentSyncHandler::class,
    DidChangeWatchedFilesHandler::class,
    DefinitionHandler::class,
    HoverHandler::class,
    SignatureHelpHandler::class,
    CompletionHandler::class,

    LifecycleHandler::class => function (TC $c) {
        $listeners = [
            $c->get(WatchedFilesRegistrar::class),
        ];
        return new LifecycleHandler(
            $c->get(CapabilityNegotiator::class),
            $listeners,
        );
    },
];
