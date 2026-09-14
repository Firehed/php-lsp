<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

return [
    SessionCapabilitiesProviderInterface::class => CapabilityNegotiator::class,
    CapabilityNegotiator::class,

    WatchedFilesRegistrar::class,
];
