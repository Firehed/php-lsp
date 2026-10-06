<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// The feature has no static server capability, so the server can only register
// it dynamically ([LSP] client/registerCapability), after `initialized`.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [],
    capabilities: new Session\ClientCapabilities(watchedFilesDynamicRegistration: true),
);
