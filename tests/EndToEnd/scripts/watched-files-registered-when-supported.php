<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\ClientCapabilities;
use Firehed\PhpLsp\Tests\EndToEnd\Script;

// The feature has no static server capability, so the server can only register
// it dynamically ([LSP] client/registerCapability), after `initialized`.
return new Script(
    project: 'tests/Fixtures',
    steps: [],
    capabilities: new ClientCapabilities(watchedFilesDynamicRegistration: true),
);
