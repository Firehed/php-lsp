<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

return [
    Document\CompositeDocumentSource::class,
    Document\DocumentManager::class,
    Document\DocumentManagerInterface::class => Document\DocumentManager::class,
    Document\DocumentSourceInterface::class => Document\CompositeDocumentSource::class,
    Parser\SourceFileReader::class,
];
