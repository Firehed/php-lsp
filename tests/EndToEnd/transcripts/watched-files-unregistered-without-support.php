<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// A client opts in to dynamic registration per capability ([LSP]
// client/registerCapability). This one declares nothing.
return new Session\Script(
    project: 'tests/Fixtures',
    steps: [],
);
