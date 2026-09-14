<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Firehed\Container\TypedContainerInterface as TC;
use Firehed\PhpLsp\Parser\SyntaxSource\SyntaxSourceInterface;

return [
    SymbolSinkInterface::class => DocumentSymbolSink::class,
    SymbolSourceInterface::class => CompositeSymbolSource::class,

    DocumentSymbolSink::class => function (TC $c) {
        $backends = [
            // FIXME: see KnowledgeStack
        ];
        return new DocumentSymbolSink(
            $c->get(DocumentSymbolStoreInterface::class),
            $c->get(DeclarationSymbolInfoFactory::class),
            $c->get(SyntaxSourceInterface::class),
            $c->get(DeclarationScanner::class),
            $backends,
        );
    },

    CompositeSymbolSource::class => function (TC $c) {
        $backends = [
            // FIXME: see KnowledgeStack
        ];
        return new CompositeSymbolSource($backends);
    },


    DocumentSymbolStoreInterface::class => OpenDocumentBackend::class,
    OpenDocumentBackend::class,
    DeclarationSymbolInfoFactory::class,
    DeclarationScanner::class,
];
