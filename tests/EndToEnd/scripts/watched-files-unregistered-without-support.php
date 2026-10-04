<?php

declare(strict_types=1);

use Firehed\PhpLsp\Tests\EndToEnd\Script;

// A client opts in to dynamic registration per capability ([LSP]
// client/registerCapability). This one declares nothing.
return new Script(
    project: 'tests/Fixtures',
    steps: [],
);
